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

    public function suspend(int $clientId, ?int $domainId, bool $alsoDisableWebsite): void
    {
        $this->setSuspended($clientId, $domainId, $alsoDisableWebsite, true);
    }

    public function unsuspend(int $clientId, ?int $domainId, bool $alsoEnableWebsite): void
    {
        $this->setSuspended($clientId, $domainId, $alsoEnableWebsite, false);
    }

    public function terminate(int $clientId): void
    {
        $this->session->call('client_delete_everything', ['client_id' => $clientId]);
    }

    public function changePassword(int $clientId, #[\SensitiveParameter] string $newPassword): void
    {
        $this->session->call('client_change_password', [
            'client_id' => $clientId,
            'new_password' => $newPassword,
        ]);
    }

    /**
     * Applies a new master template and reconciles addon-template
     * assignments to exactly $addonTemplateIds: currently assigned ids are
     * discovered via client_get, whose template_additional comes back as
     * 'assigned_template_id:client_template_id' pairs joined by '/'
     * (client.inc.php client_get()).
     *
     * @param list<int> $addonTemplateIds the DESIRED addon template ids
     *        (resolved by the caller via Mapper::addonTemplateIds())
     */
    public function changePackage(int $clientId, ProductConfig $product, array $addonTemplateIds): void
    {
        // Partial update is safe: client_update merges the stored record
        // first (remoting updateQueryPrepare(), spec §1.5).
        $this->session->call('client_update', [
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['template_master' => $product->clientTemplateId],
        ]);

        $record = $this->session->call('client_get', ['client_id' => $clientId]);
        $current = self::assignedTemplateIds(is_array($record) ? $record : []);
        $desired = array_values(array_unique(array_map(intval(...), $addonTemplateIds)));

        foreach ($desired as $templateId) {
            if (!in_array($templateId, $current, true)) {
                $this->session->call('client_template_additional_add', [
                    'client_id' => $clientId,
                    'template_id' => $templateId,
                ]);
            }
        }

        foreach (array_unique($current) as $templateId) {
            if (!in_array($templateId, $desired, true)) {
                // Source quirk (client.inc.php client_template_additional_
                // delete()): the assigned_template_id PARAMETER is matched
                // against client_template_id, so the TEMPLATE id - not the
                // assignment id - is what removes an assignment.
                $this->session->call('client_template_additional_delete', [
                    'client_id' => $clientId,
                    'assigned_template_id' => $templateId,
                ]);
            }
        }
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

    private function setSuspended(int $clientId, ?int $domainId, bool $alsoToggleWebsite, bool $suspended): void
    {
        $this->session->call('client_update', [
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['locked' => $suspended ? 'y' : 'n'],
        ]);

        if ($alsoToggleWebsite && $domainId !== null) {
            $this->session->call('sites_web_domain_set_status', [
                'primary_id' => $domainId,
                'status' => $suspended ? 'inactive' : 'active',
            ]);
        }
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

    /**
     * Parses client_get's template_additional field: current-style
     * 'assigned_template_id:client_template_id' pairs joined by '/', or
     * legacy bare template ids joined by '/' (client.inc.php
     * _set_client_formdata() still supports the old storage).
     *
     * @param array<string, mixed> $clientRecord
     * @return list<int> client_template ids currently assigned
     */
    private static function assignedTemplateIds(array $clientRecord): array
    {
        $raw = trim((string) ($clientRecord['template_additional'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $ids = [];
        foreach (explode('/', $raw) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $parts = explode(':', $item);
            $ids[] = (int) end($parts);
        }

        return $ids;
    }
}
