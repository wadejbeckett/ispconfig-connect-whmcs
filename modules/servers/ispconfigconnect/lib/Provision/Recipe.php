<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Provision;

use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Support\ProductConfig;
use WHMCS\Module\Server\Ispconfigconnect\Support\ServiceParams;

/**
 * Multi-call provisioning sequences on top of SessionManager (spec 2.7).
 * Field mapping lives in Mapper; this class owns call order, optional-stage
 * gating, and the all-or-nothing rollback of create(). Params never include
 * session_id - SessionManager owns it.
 */
final class Recipe
{
    public function __construct(private readonly SessionManager $session)
    {
    }

    /**
     * All-or-nothing create. Once client_add has succeeded, ANY later
     * failure triggers a best-effort client_delete_everything rollback and
     * surfaces as ProvisioningException; a failure of client_add itself
     * propagates untouched (nothing exists yet to roll back).
     */
    public function create(ProductConfig $product, ServiceParams $service): CreateResult
    {
        $clientId = (int) $this->session->call('client_add', [
            'reseller_id' => 0,
            'params' => Mapper::clientAddParams($product, $service),
        ]);

        $domainId = null;
        $ftpUserId = null;
        $mailDomainId = null;
        $dnsZoneId = null;
        $databaseUserId = null;
        $databaseId = null;

        $stage = 'addon_templates';
        try {
            if (self::hasAddonOptions($service)) {
                $templates = $this->session->call('client_templates_get_all', []);
                $templateIds = Mapper::addonTemplateIds($service, is_array($templates) ? $templates : []);
                foreach ($templateIds as $templateId) {
                    $this->session->call('client_template_additional_add', [
                        'client_id' => $clientId,
                        'template_id' => $templateId,
                    ]);
                }
            }

            if ($product->createWebsite) {
                $stage = 'web_domain';
                $domainId = (int) $this->session->call('sites_web_domain_add', [
                    'client_id' => $clientId,
                    'params' => Mapper::webDomainAddParams($product, $service),
                    'readonly' => false,
                ]);
                // Re-fetch: remoting fills system_user/system_group/
                // document_root server-side (remote.d/sites.inc.php), and the
                // FTP user needs them.
                $webDomainRecord = $this->session->call('sites_web_domain_get', [
                    'primary_id' => $domainId,
                ]);
                if (!is_array($webDomainRecord)) {
                    throw new \RuntimeException(
                        'sites_web_domain_get returned no record for domain_id ' . $domainId
                    );
                }

                // FTP is gated on the website too: uid/gid/dir have no source
                // without a web domain record.
                if ($product->createFtpUser) {
                    $stage = 'ftp_user';
                    $ftpUserId = (int) $this->session->call('sites_ftp_user_add', [
                        'client_id' => $clientId,
                        'params' => Mapper::ftpUserAddParams($service, $webDomainRecord),
                    ]);
                }
            }

            if ($product->createMailDomain) {
                $stage = 'mail_domain';
                $mailDomainId = (int) $this->session->call('mail_domain_add', [
                    'client_id' => $clientId,
                    'params' => Mapper::mailDomainAddParams($product, $service),
                ]);
            }

            if ($product->createDnsZone) {
                $stage = 'dns_zone';
                $this->session->call(
                    'dns_templatezone_add',
                    ['client_id' => $clientId] + Mapper::dnsTemplateArgs($product, $service)
                );
                // dns_templatezone_add returns bool only; dns_zone_get_id
                // resolves the id and matches origin with OR without the
                // trailing dot the template stores - but REJECTS a
                // trailing-dot QUERY, so the bare domain is passed
                // (remote.d/dns.inc.php dns_zone_get_id()).
                $dnsZoneId = (int) $this->session->call('dns_zone_get_id', [
                    'origin' => $service->domain,
                ]);
            }

            if ($product->createDatabase) {
                $names = Mapper::databaseNames($service, $clientId);

                // sites_database_add requires a pre-created database user id
                // (remote.d/sites.inc.php), so the user always goes first.
                $stage = 'database_user';
                $databaseUserId = (int) $this->session->call('sites_database_user_add', [
                    'client_id' => $clientId,
                    'params' => [
                        'server_id' => $product->webServerId,
                        'database_user' => $names['user'],
                        'database_password' => $service->password,
                    ],
                ]);

                $stage = 'database';
                $databaseId = (int) $this->session->call('sites_database_add', [
                    'client_id' => $clientId,
                    'params' => [
                        'server_id' => $product->webServerId,
                        'type' => 'mysql',
                        'parent_domain_id' => $domainId ?? 0,
                        'database_name' => $names['name'],
                        'database_user_id' => $databaseUserId,
                        'database_charset' => '',
                        'remote_access' => 'n',
                        'remote_ips' => '',
                        'active' => 'y',
                    ],
                ]);
            }
        } catch (\Throwable $failure) {
            $this->rollback($clientId, $stage, $failure);
        }

        return new CreateResult(
            clientId: $clientId,
            username: $service->username,
            domainId: $domainId,
            ftpUserId: $ftpUserId,
            mailDomainId: $mailDomainId,
            dnsZoneId: $dnsZoneId,
            databaseUserId: $databaseUserId,
            databaseId: $databaseId,
        );
    }

    /**
     * Entire-client mode: locked='y' via a protective client_update -
     * func_client_lock (functions.inc.php) then disables EVERY object the
     * client owns (web, mail, FTP, DB, cron, shell, ...) and remembers the
     * already-inactive ones in client.tmp_data. Locking an ALREADY-locked
     * client would overwrite tmp_data with everything-inactive so a later
     * unlock restores nothing - hence the client_get guard.
     *
     * Website-only mode: a lock that spares the website does not exist, so
     * no client_update is made at all - just sites_web_domain_set_status.
     */
    public function suspend(int $clientId, ?int $domainId, bool $suspendEntireClient): void
    {
        if (!$suspendEntireClient) {
            if ($domainId !== null) {
                $this->setWebsiteStatus($domainId, 'inactive');
            }

            return;
        }

        $record = $this->clientRecord($clientId);
        if ((string) ($record['locked'] ?? 'n') === 'y') {
            return; // already locked - re-locking poisons tmp_data
        }

        $this->protectiveClientUpdate($clientId, ['locked' => 'y'], $record);
    }

    /**
     * Symmetric inverse of suspend() with the same guards. The unlock runs
     * only when the client is actually locked: client_update handles ANY
     * truthy 'locked' value - 'n' included (client.inc.php: if($params
     * ['locked']) on the merged record) - and func_client_lock('n') would
     * re-enable objects an admin disabled on purpose. No explicit website
     * set_status in entire-client mode either: the unlock restores every
     * object except those tmp_data remembers as previously-inactive
     * (functions.inc.php func_client_lock).
     */
    public function unsuspend(int $clientId, ?int $domainId, bool $suspendEntireClient): void
    {
        if (!$suspendEntireClient) {
            if ($domainId !== null) {
                $this->setWebsiteStatus($domainId, 'active');
            }

            return;
        }

        $record = $this->clientRecord($clientId);
        if ((string) ($record['locked'] ?? 'n') !== 'y') {
            return; // not locked - nothing to restore
        }

        $this->protectiveClientUpdate($clientId, ['locked' => 'n'], $record);
    }

    public function terminate(int $clientId): void
    {
        $this->session->call('client_delete_everything', ['client_id' => $clientId]);
    }

    /**
     * Rotates every credential the module provisioned with the service
     * password: the panel login always, plus the FTP user and the database
     * user when their ids are given. Both *_update functions use a plain
     * updateQuery (remote.d/sites.inc.php) - no client_update-style
     * pre-merge - so the partial params are safe. Field names per the form
     * definitions: 'password' (ftp_user.tform.php, PASSWORD/CRYPT) and
     * 'database_password' (database_user.tform.php, PASSWORD/MYSQL).
     */
    public function changePassword(
        int $clientId,
        #[\SensitiveParameter] string $newPassword,
        ?int $ftpUserId = null,
        ?int $databaseUserId = null,
    ): void {
        $this->session->call('client_change_password', [
            'client_id' => $clientId,
            'new_password' => $newPassword,
        ]);

        if ($ftpUserId !== null) {
            $this->session->call('sites_ftp_user_update', [
                'client_id' => $clientId,
                'primary_id' => $ftpUserId,
                'params' => ['password' => $newPassword],
            ]);
        }

        if ($databaseUserId !== null) {
            $this->session->call('sites_database_user_update', [
                'client_id' => $clientId,
                'primary_id' => $databaseUserId,
                'params' => ['database_password' => $newPassword],
            ]);
        }
    }

    /**
     * Applies a new master template and reconciles addon-template
     * assignments to exactly $addonTemplateIds in ONE protective
     * client_update: template_additional carries the DESIRED bare template
     * ids, and update_client_templates' old-style count-diff
     * (client_templates.inc.php: bare ids are counted against the assigned
     * rows, surplus inserted, deficit deleted) applies the delta atomically
     * server-side. Unchanged assignments keep their rows; no separate
     * client_template_additional_add/_delete calls are needed.
     *
     * @param list<int> $addonTemplateIds the DESIRED addon template ids
     *        (resolved by the caller via Mapper::addonTemplateIds())
     */
    public function changePackage(int $clientId, ProductConfig $product, array $addonTemplateIds): void
    {
        $desired = [];
        foreach ($addonTemplateIds as $templateId) {
            $templateId = (int) $templateId;
            // Skip malformed/zero entries: a 0 would count-diff a bogus
            // template id, a duplicate would double-assign the template.
            if ($templateId > 0 && !in_array($templateId, $desired, true)) {
                $desired[] = $templateId;
            }
        }

        $this->protectiveClientUpdate($clientId, [
            'template_master' => $product->clientTemplateId,
            'template_additional' => implode('/', $desired),
        ]);
    }

    public function jobqueueCount(?int $serverId = null): int
    {
        // server_id 0 = all servers (remote.d/monitor.inc.php default).
        return (int) $this->session->call('monitor_jobqueue_count', [
            'server_id' => $serverId ?? 0,
        ]);
    }

    /**
     * Best-effort rollback of a partially created account, then rethrow the
     * original failure wrapped with stage + rollback outcome.
     */
    private function rollback(int $clientId, string $stage, \Throwable $failure): never
    {
        $rollbackFailure = null;
        try {
            $this->session->call('client_delete_everything', ['client_id' => $clientId]);
        } catch (\Throwable $deleteFailure) {
            $rollbackFailure = $deleteFailure;
        }

        throw new ProvisioningException(
            $stage,
            $rollbackFailure === null,
            $clientId,
            $failure,
            $rollbackFailure
        );
    }

    /**
     * client_update is NEVER safe as a plain partial update: it merges the
     * stored DB record into $params BEFORE updateQuery (client.inc.php
     * client_update()), with two destructive consequences this wrapper
     * neutralizes on every call:
     *
     *  1. The stored crypt hash lands in params['password'] and would be
     *     re-crypted by getSQL / ispconfig_sysuser_update
     *     (remoting_lib.inc.php) - corrupting the panel login - unless
     *     '_ispconfig_pw_crypted' => 1 passes it through verbatim.
     *  2. The merged record always carries the template_additional COLUMN
     *     ('' when assignments were made via the API), and client_update
     *     then runs update_client_templates() with it - '' deletes EVERY
     *     client_template_assigned row (client_templates.inc.php). Sending
     *     the currently-assigned bare template ids makes that resync a
     *     no-op (old-style count-diff: identical counts change nothing);
     *     callers that WANT a different assignment set (changePackage) pass
     *     their own template_additional.
     *
     * @param array<string, int|string> $params the intended changes
     */
    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed>|null $record client_get result, when the caller already fetched it
     */
    private function protectiveClientUpdate(int $clientId, array $params, ?array $record = null): void
    {
        if (!array_key_exists('template_additional', $params)) {
            $params['template_additional'] = implode('/', $this->assignedAddonTemplateIds($clientId));
        }
        $params['_ispconfig_pw_crypted'] = 1;

        // Template application overwrites the client's web_php_options /
        // ssh_chroot with the TEMPLATE's values on every add/update - and a
        // template that leaves them empty (live-verified on 3.3.1p1) empties
        // the client record, after which any partial client_update dies on
        // the fields' NOTEMPTY validators. Re-send the current values, or
        // permissive defaults when the template gap has already emptied them.
        $record ??= $this->clientRecord($clientId);
        $params['web_php_options'] = ($record['web_php_options'] ?? '') !== ''
            ? $record['web_php_options'] : 'no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm';
        $params['ssh_chroot'] = ($record['ssh_chroot'] ?? '') !== ''
            ? $record['ssh_chroot'] : 'no,jailkit';

        $this->session->call('client_update', [
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => $params,
        ]);
    }

    /**
     * The client's current addon-template assignments as bare template ids,
     * via client_template_additional_get (client.inc.php: returns the raw
     * client_template_assigned rows; grant alias 'client_get'). Duplicates
     * are preserved on purpose - the old-style count-diff in
     * update_client_templates counts per template id, so dropping one of two
     * identical assignments here would delete one server-side.
     *
     * @return list<int>
     */
    private function assignedAddonTemplateIds(int $clientId): array
    {
        $rows = $this->session->call('client_template_additional_get', [
            'client_id' => $clientId,
        ]);

        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = is_array($row) ? (int) ($row['client_template_id'] ?? 0) : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The stored 'locked' flag ('y'/'n'), or null when the client record
     * cannot be read (client_get returns null for a missing id -
     * db_mysql.inc.php queryOneRecord).
     */
    /** @return array<string, mixed> */
    private function clientRecord(int $clientId): array
    {
        $record = $this->session->call('client_get', ['client_id' => $clientId]);

        return is_array($record) ? $record : [];
    }

    private function setWebsiteStatus(int $domainId, string $status): void
    {
        $this->session->call('sites_web_domain_set_status', [
            'primary_id' => $domainId,
            'status' => $status,
        ]);
    }

    /**
     * Any configurable option named 'ISPConfig <Addon-Template-Name>' binds
     * to an addon client template (design §3.4; the prefix mirrors
     * Mapper::ADDON_OPTION_PREFIX). Only then is the extra
     * client_templates_get_all lookup worth a wire call.
     */
    private static function hasAddonOptions(ServiceParams $service): bool
    {
        foreach (array_keys($service->configOptions) as $optionName) {
            if (str_starts_with((string) $optionName, 'ISPConfig ')) {
                return true;
            }
        }

        return false;
    }

}
