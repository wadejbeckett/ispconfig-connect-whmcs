<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Sync;

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\RemoteFaultException;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;

/**
 * The data side of WHMCS's ListAccounts / "Sync Accounts" feature: every
 * ISPConfig client on the panel, with its primary website, master template and
 * lock state (developers.whmcs.com/provisioning-modules/server-sync/).
 *
 * Call budget: 2 fixed calls (client_templates_get_all + one wildcard
 * client_get) plus 2 per client (client_get_by_username, then
 * client_get_sites_by_user - which needs the client's sys_user id and group,
 * neither of which is on the client row: client.sys_userid/sys_groupid belong
 * to the client's OWNER, verified in remoting_lib.inc.php getSQL/loadUserProfile).
 * Templates are fetched once for the whole panel, never per client.
 *
 * Every call goes through SessionManager (one login per batch); the caller
 * owns close(). Lib code, so no WHMCS calls and no echoing.
 */
final class AccountLister
{
    /**
     * The three MetaData keys WHMCS requires before it offers Sync Accounts,
     * kept next to the mapping that has to satisfy them
     * (developers.whmcs.com/provisioning-modules/server-sync/). The entry
     * file's _MetaData() should spread these in.
     *
     * - the identifier field is 'uniqueIdentifier', NOT 'domain': a client
     *   without a website has no domain, and this module falls back to the
     *   panel username there so every row stays distinct;
     * - 'configoption1' is the Client Template dropdown (frozen option order,
     *   spec 3.1). WHMCS stores a Loader dropdown's array KEY, so that option
     *   holds the ISPConfig template id - which is what RemoteAccount::product
     *   emits.
     *
     * @var array<string, string>
     */
    public const WHMCS_METADATA = [
        'ListAccountsUniqueIdentifierDisplayName' => 'Domain',
        // MUST name a field WHMCS itself can match a service on. Core throws
        // "Unsupported unique identifier field provided by module" for
        // anything else (live-verified on WHMCS 9.0.6:
        // Admin/Utilities/Tools/ServerSync/SyncItem::determineServiceMatches()),
        // and the value 'uniqueIdentifier' - the docs' name for the account
        // ARRAY key - is one of the rejected ones. The bundled cPanel and
        // Plesk modules both ship 'domain'.
        'ListAccountsUniqueIdentifierField' => 'domain',
        'ListAccountsProductField' => 'configoption1',
    ];

    /**
     * remoting_lib.inc.php getDataRecord(): -1 returns every row of the form's
     * table. The remote API always runs with admin privileges
     * (loadUserProfile(0)), so this is the whole client table.
     */
    private const ALL_CLIENT_RECORDS = -1;

    /**
     * @param string $primaryIp the address WHMCS reports as the account's
     *        primary IP (the server's IP - ISPConfig stores '*' on vhosts and
     *        client_get_sites_by_user does not return an address at all)
     * @param \DateTimeZone|null $timezone zone for the 'Y-m-d H:i:s' created
     *        stamp; defaults to PHP's configured zone (WHMCS sets it)
     */
    public function __construct(
        private readonly SessionManager $session,
        private readonly string $primaryIp = '',
        private readonly ?\DateTimeZone $timezone = null,
    ) {
    }

    /** @return list<RemoteAccount> ordered by ISPConfig client id */
    public function listAccounts(): array
    {
        $templateNames = $this->templateNames();

        $accounts = [];
        foreach ($this->clientRecords() as $client) {
            $accounts[] = $this->account($client, $templateNames);
        }

        return $accounts;
    }

    /**
     * The `accounts` member of a WHMCS ListAccounts response.
     *
     * @param list<RemoteAccount> $accounts
     * @return list<array{
     *     email: string, username: string, domain: string,
     *     uniqueIdentifier: string, product: string, primaryip: string,
     *     created: string, status: string
     * }>
     */
    public static function toWhmcsAccounts(
        array $accounts,
        string $activeStatus = RemoteAccount::STATUS_ACTIVE,
        string $suspendedStatus = RemoteAccount::STATUS_SUSPENDED,
    ): array {
        return array_map(
            static fn (RemoteAccount $account): array => $account->toWhmcsArray($activeStatus, $suspendedStatus),
            $accounts
        );
    }

    /**
     * client_templates_get_all returns master AND addon templates; ids are
     * unique across both, so one flat map serves template_master lookups.
     *
     * @return array<int, string>
     */
    private function templateNames(): array
    {
        $response = $this->session->call('client_templates_get_all', []);

        $names = [];
        foreach (is_array($response) ? $response : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['template_id'] ?? 0);
            if ($id > 0) {
                $names[$id] = trim((string) ($row['template_name'] ?? ''));
            }
        }

        return $names;
    }

    /**
     * Every client row in one call, ordered by client id so the listing is
     * stable across syncs.
     *
     * @return list<array<string, mixed>>
     */
    private function clientRecords(): array
    {
        $response = $this->session->call('client_get', ['client_id' => self::ALL_CLIENT_RECORDS]);

        $records = [];
        foreach (is_array($response) ? $response : [] as $row) {
            // A single-record response (defensive: a panel that ignores -1)
            // has scalar members, not rows.
            if (is_array($row) && isset($row['client_id'])) {
                $records[] = $row;
            }
        }
        if ($records === [] && is_array($response) && isset($response['client_id'])) {
            /** @var array<string, mixed> $response */
            $records[] = $response;
        }

        usort(
            $records,
            static fn (array $a, array $b): int => (int) ($a['client_id'] ?? 0) <=> (int) ($b['client_id'] ?? 0)
        );

        return $records;
    }

    /**
     * @param array<string, mixed> $client
     * @param array<int, string> $templateNames
     */
    private function account(array $client, array $templateNames): RemoteAccount
    {
        $username = trim((string) ($client['username'] ?? ''));
        $site = $this->primarySite($username);

        $domain = $site === null ? '' : trim((string) ($site['domain'] ?? ''));
        $domainId = $site === null ? null : (int) ($site['domain_id'] ?? 0);
        $templateId = (int) ($client['template_master'] ?? 0);

        return new RemoteAccount(
            clientId: (int) ($client['client_id'] ?? 0),
            username: $username,
            email: trim((string) ($client['email'] ?? '')),
            domain: $domain,
            // WHMCS matches rows by this value, so a website-less client falls
            // back to its panel username rather than colliding on ''.
            uniqueIdentifier: $domain !== '' ? $domain : $username,
            product: $templateId > 0 ? (string) $templateId : '',
            templateName: $templateNames[$templateId] ?? '',
            primaryIp: $this->primaryIp,
            created: $this->formatCreated($client['created_at'] ?? null),
            // 'locked' is ISPConfig's whole-client suspension; an inactive
            // primary vhost is how the module's website-only suspend mode
            // (config option 13 unchecked) leaves the account.
            suspended: (string) ($client['locked'] ?? 'n') === 'y'
                || ($site !== null && (string) ($site['active'] ?? 'y') !== 'y'),
            domainId: $domainId !== null && $domainId > 0 ? $domainId : null,
        );
    }

    /**
     * The client's oldest vhost - the one the module provisions first, and a
     * stable choice for panels where a client hosts several sites.
     *
     * @return array<string, mixed>|null
     */
    private function primarySite(string $username): ?array
    {
        if ($username === '') {
            return null; // no sys_user to resolve the site ownership with
        }

        $sysUser = $this->tolerantCall('client_get_by_username', ['username' => $username]);
        if (!is_array($sysUser)) {
            return null;
        }

        $userId = (int) ($sysUser['userid'] ?? 0);
        // sys_user.groups is the comma-separated group list; default_group is
        // the fallback for rows that predate it (remoting_lib.inc.php
        // ispconfig_sysuser_add writes both).
        $groups = trim((string) ($sysUser['groups'] ?? ''));
        if ($groups === '') {
            $groups = (string) (int) ($sysUser['default_group'] ?? 0);
        }
        if ($userId <= 0 && (int) $groups <= 0) {
            return null;
        }

        $sites = $this->tolerantCall('client_get_sites_by_user', [
            'sys_userid' => $userId,
            // sites.inc.php explode()s this - it must be a string, never an array.
            'sys_groupid' => $groups,
        ]);

        $primary = null;
        foreach (is_array($sites) ? $sites : [] as $site) {
            if (!is_array($site) || !isset($site['domain_id'])) {
                continue;
            }
            if ($primary === null || (int) $site['domain_id'] < (int) $primary['domain_id']) {
                $primary = $site;
            }
        }

        return $primary;
    }

    /**
     * Per-client lookups must not sink the whole listing: a client row whose
     * sys_user is gone makes client_get_by_username throw 'There is no user
     * account for this user name.' (remote.d/client.inc.php). Only
     * UNCLASSIFIED remote faults are tolerated - every typed subclass
     * (permission denied, lockout, expired session) still propagates, so a
     * missing grant is reported instead of silently listing domain-less
     * accounts.
     *
     * @param array<string, mixed> $params
     */
    private function tolerantCall(string $method, array $params): mixed
    {
        try {
            return $this->session->call($method, $params);
        } catch (RemoteFaultException $fault) {
            if ($fault::class !== RemoteFaultException::class) {
                throw $fault;
            }

            return null;
        }
    }

    /**
     * client.created_at is a unix timestamp written once by
     * functions.inc.php generate_ssh_key(); it stays NULL when key generation
     * failed, and WHMCS wants 'Y-m-d H:i:s'.
     */
    private function formatCreated(mixed $createdAt): string
    {
        if (!is_numeric($createdAt) || (int) $createdAt <= 0) {
            return '';
        }

        return (new \DateTimeImmutable('@' . (int) $createdAt))
            ->setTimezone($this->timezone ?? new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
    }
}
