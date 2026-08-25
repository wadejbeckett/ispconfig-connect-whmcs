<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Usage;

use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;

/**
 * Reads one ISPConfig client's usage over SessionManager and hands back a
 * ClientUsage. Owns the call SET (which remote functions, in which order) and
 * the per-run memo; the arithmetic lives in UsageParser, the units in
 * QuotaMath. No WHMCS calls, no echo.
 *
 * Two depths, because the two consumers pay different prices:
 *   - usageFor()   : 2 calls  - disk + traffic, all UsageUpdate needs.
 *   - metricsFor() : up to 6  - adds mail and database quota and the FTP/DNS
 *                    listings the MetricProvider snapshot counts need.
 * Either depth spends ONE extra client_get when every quota answer came back
 * empty, because a deleted panel client produces exactly the same empty
 * answers as a client that owns nothing (see assertAttributable()).
 */
final class Collector
{
    /** @var array<string, ClientUsage> memo key "<clientId>|<depth>" */
    private array $cache = [];

    public function __construct(private readonly SessionManager $session)
    {
    }

    /**
     * Disk and bandwidth only.
     *
     * @throws UsageException when the response cannot be attributed to $clientId
     */
    public function usageFor(int $clientId): ClientUsage
    {
        $key = $clientId . '|usage';
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $diskQuota = $this->session->call('quota_get_by_user', ['client_id' => $clientId]);
        $traffic = $this->session->call('trafficquota_get_by_user', [
            'client_id' => $clientId,
            // 0 = "skip the last-N-days query" (remote.d/sites.inc.php); the
            // module bills on this_month, so the extra SUM is waste.
            'lastdays' => 0,
        ]);

        $usage = UsageParser::parse(clientId: $clientId, diskQuota: $diskQuota, traffic: $traffic);
        $this->assertAttributable($clientId, $usage);

        return $this->cache[$key] = $usage;
    }

    /**
     * Everything the MetricProvider reports.
     *
     * @param string $username the ISPConfig client username; only used when no
     *        record carried a sys_groupid (a DNS-only client owns no website,
     *        mailbox or database), in which case it is resolved from
     *        sys_user.default_group via client_get_by_username
     * @throws UsageException when the response cannot be attributed to $clientId
     */
    public function metricsFor(int $clientId, string $username = ''): ClientUsage
    {
        $key = $clientId . '|metrics';
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $diskQuota = $this->session->call('quota_get_by_user', ['client_id' => $clientId]);
        $traffic = $this->session->call('trafficquota_get_by_user', [
            'client_id' => $clientId,
            'lastdays' => 0,
        ]);

        $usage = UsageParser::parse(
            clientId: $clientId,
            diskQuota: $diskQuota,
            traffic: $traffic,
            mailQuota: $this->session->call('mailquota_get_by_user', ['client_id' => $clientId]),
            databaseQuota: $this->session->call('databasequota_get_by_user', ['client_id' => $clientId]),
        );
        $this->assertAttributable($clientId, $usage);

        $usage = $usage->withSysGroupId($usage->sysGroupId ?? $this->resolveGroupId($username));
        if ($usage->sysGroupId === null) {
            // Without a group there is no safe filter for the two listings,
            // and an unfiltered *_get would return the whole panel.
            return $this->cache[$key] = $usage;
        }

        // remoting_lib::getDataRecord() turns an ARRAY argument into a
        // "WHERE column = value" filter and returns a list. THIS path runs
        // admin-typed - getDataRecord() calls loadUserProfile(0)
        // (remoting_lib.inc.php:251), which sets typ='admin' (:143) and takes
        // getAuthSQL's '1' shortcut (tform_base.inc.php:1633) - so nothing
        // narrows the filter; the group id is the whole scope.
        $filter = ['sys_groupid' => $usage->sysGroupId];
        $usage = $usage->withEntityCounts(
            ftpUserCount: self::countRows($this->session->call('sites_ftp_user_get', ['primary_id' => $filter])),
            dnsZoneCount: self::countRows($this->session->call('dns_zone_get', ['primary_id' => $filter])),
        );

        return $this->cache[$key] = $usage;
    }

    /**
     * quota_lib scopes its queries with
     * getAuthSQL('r', '', '', clientid_to_groups_list($clientid))
     * (quota_lib.inc.php:18/:224/:290). Two hazards hide in that scoping:
     *
     * 1. A RESELLER id: clientid_to_groups_list() concatenates the client's
     *    own group with its sub-clients' groups (functions.inc.php:687, the
     *    parent_client_id branch), so the records span several sys_groupids.
     *    This module never provisions resellers, and summing other clients'
     *    sites into one service's bill would be a catastrophic overcharge -
     *    the span is refused.
     *
     * 2. A DELETED client id - a service whose panel client was removed by
     *    hand: clientid_to_groups_list() returns NULL, and getAuthSQL()'s
     *    fallback clause then matches NOTHING. (Not the whole panel: the '1'
     *    shortcut is gated on $_SESSION['s']['user']['typ'] == 'admin',
     *    tform_base.inc.php:1633, and the only typ='admin' writes are in
     *    loadUserProfile, remoting_lib.inc.php:143/:155, which the quota
     *    remote path never calls; with no session groups the WHERE collapses
     *    to "sys_perm_other like '%r%'" against rows whose perm_other is ''.)
     *    So a stale id answers every quota call with ZERO rows and code ok -
     *    byte-identical to a client that simply owns nothing. Importing that
     *    as 0 forever would be silent wrong billing, so an all-empty answer is
     *    only accepted after client_get confirms the client row still exists
     *    (a miss is null - queryOneRecord, remoting_lib getDataRecord).
     *
     * @throws UsageException
     */
    private function assertAttributable(int $clientId, ClientUsage $usage): void
    {
        if (count($usage->sysGroupIds) > 1) {
            throw new UsageException(sprintf(
                'Usage for ISPConfig client #%d spans %d sys_groupid values (%s) and cannot be '
                    . 'attributed to one service - the id points at a reseller or at records this '
                    . 'module did not provision.',
                $usage->clientId,
                count($usage->sysGroupIds),
                implode(', ', $usage->sysGroupIds)
            ));
        }

        if ($usage->websiteCount === 0 && $usage->mailboxCount === 0 && $usage->databaseCount === 0) {
            $record = $this->session->call('client_get', ['client_id' => $clientId]);
            if (!is_array($record)) {
                throw new UsageException(sprintf(
                    'ISPConfig client #%d no longer exists on the panel - the stored client id is '
                        . 'stale and usage was not imported (a deleted client answers every quota '
                        . 'call with zero rows, not an error).',
                    $clientId
                ));
            }
        }
    }

    /**
     * sys_user.default_group for the client, via client_get_by_username
     * (remote.d/client.inc.php returns the sys_user row, not the client row).
     * The client's own group is the one ispconfig_sysuser_add created for it -
     * client.sys_groupid is the CREATOR's group and would be the wrong answer.
     *
     * A failure here propagates on purpose. This runs only when the client
     * owns no website, mailbox or database, so the storage metrics are all
     * zero anyway and nothing is lost by failing loudly - while a username
     * that no longer resolves means the module's stored identity for the
     * service is wrong, which the operator has to see.
     */
    private function resolveGroupId(string $username): ?int
    {
        if ($username === '') {
            return null;
        }

        $record = $this->session->call('client_get_by_username', ['username' => $username]);
        if (!is_array($record)) {
            return null;
        }
        $groupId = QuotaMath::toInt($record['default_group'] ?? null);

        return $groupId > 0 ? $groupId : null;
    }

    private static function countRows(mixed $response): int
    {
        return is_array($response) ? count($response) : 0;
    }
}
