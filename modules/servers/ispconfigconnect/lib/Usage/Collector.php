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
     * @throws UsageException when $clientId is not a usable panel id, or when
     *         the response cannot be attributed to it
     */
    public function usageFor(int $clientId): ClientUsage
    {
        self::assertUsableClientId($clientId);

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
     * @throws UsageException when $clientId is not a usable panel id, or when
     *         the response cannot be attributed to it
     */
    public function metricsFor(int $clientId, string $username = ''): ClientUsage
    {
        self::assertUsableClientId($clientId);

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

        $usage = $usage->withSysGroupId($usage->sysGroupId ?? $this->resolveGroupId($clientId, $username));
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
     * A FALSY client id must never reach the panel, because ISPConfig does not
     * fail on one - it answers with somebody else's numbers.
     *
     * trafficquota_get_by_user() gates its scoping on the LOOSE
     * "$clientid != null" (quota_lib.inc.php:80, after the identical gate at
     * remote.d/sites.inc.php:992). PHP fails that test for 0, '0', '' and
     * null, and a non-numeric string clears the first gate only to be
     * intval()'d to 0 and fail the second. When it fails, $sql_where is never
     * assigned and the SELECT runs unscoped:
     *
     *   SELECT * FROM web_domain WHERE active = 'y'
     *     AND (type='vhost' OR type='vhostsubdomain' OR type='vhostalias')
     *
     * - every site on the panel, each with its own SUM(traffic_bytes). The
     * disk, mail and database calls do NOT degrade the same way
     * (clientid_to_groups_list(0) returns NULL, functions.inc.php:685, and
     * getAuthSQL then falls back to "sys_perm_other like '%r%'" against
     * columns that hold NULL/''), so a falsy id produces ZERO entity counts
     * beside PANEL-WIDE bandwidth. That combination does get stopped further
     * downstream today - assertAttributable() sees all-empty counts and probes
     * client_get, which rejects id 0 outright ("The ID has to be > 0 or -1",
     * remoting_lib) - but only by accident of the counts being empty: a single
     * row anywhere with a readable sys_perm_other would satisfy the
     * attribution check and let the panel's whole month of traffic through as
     * one service's bill. And the unscoped read has already happened by then.
     *
     * The id arrives from the ispconfig_client_id service property, and
     * ispconfigconnect_serviceRefs() keeps services that have none on purpose
     * (so the operator sees them reported as skipped) - so 0 is a value this
     * code really is handed. Refuse it here, below every caller and before
     * the wire call, rather than relying on each caller to check.
     *
     * @throws UsageException
     */
    private static function assertUsableClientId(int $clientId): void
    {
        if ($clientId > 0) {
            return;
        }

        throw new UsageException(sprintf(
            'Refusing to read usage for ISPConfig client id %d: the panel does not reject a '
                . 'non-positive client id, it answers trafficquota_get_by_user with EVERY domain '
                . 'on the server (quota_lib.inc.php:80), which would bill one service for the '
                . 'whole panel. The service has no usable ispconfig_client_id.',
            $clientId
        ));
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
     *    KNOWN RESIDUAL: the test is "did the ANSWER span more than one
     *    group", not "is this id a reseller", so it only fires once two
     *    groups are actually represented in the rows. A reseller that owns
     *    nothing itself and has exactly ONE sub-client produces rows from a
     *    single group and passes. Closing that would cost a
     *    client_get_by_username per service per sweep to compare the observed
     *    group against sys_user.default_group; it is not paid, because the
     *    module only ever stores the id of a client it created itself (which
     *    by construction has no sub-clients) and reaching the hole requires an
     *    operator to hand-link a service to a reseller row.
     *
     * 2. A DELETED client id - a service whose panel client was removed by
     *    hand: clientid_to_groups_list() returns NULL, and getAuthSQL()'s
     *    fallback clause then matches NOTHING. (Not the whole panel: the '1'
     *    shortcut is gated on $_SESSION['s']['user']['typ'] == 'admin',
     *    tform_base.inc.php:1633, and the only typ='admin' writes are in
     *    loadUserProfile, remoting_lib.inc.php:143/:155, which the quota
     *    remote path never calls; with no session groups the WHERE collapses
     *    to "sys_perm_other like '%r%'", and that column holds '' on a
     *    tform-written web_domain row (web_vhost_domain.tform.php:76
     *    auth_preset, over a schema default of NULL, ispconfig3.sql:1996) and
     *    '' on mail_user (:1067) - neither matches.)
     *    So a stale id answers every quota call with ZERO rows and code ok -
     *    byte-identical to a client that simply owns nothing. Importing that
     *    as 0 forever would be silent wrong billing, so an all-empty answer is
     *    only accepted after client_get confirms the client row still exists
     *    (a miss is null - queryOneRecord, remoting_lib getDataRecord).
     *
     *    A DELETED id degrades safely only because it is still non-zero:
     *    trafficquota_get_by_user scopes on "sys_groupid = (SELECT
     *    default_group FROM sys_user WHERE client_id=?)" (quota_lib.inc.php:81)
     *    rather than on getAuthSQL, and that subselect yields NULL - no match.
     *    A FALSY id skips that WHERE entirely and reads the whole panel, which
     *    is why assertUsableClientId() refuses it before the call rather than
     *    leaving it to this check.
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
     *
     * The resolved sys_user row MUST belong to $clientId. client_get_by_username
     * keys on the username alone (remote.d/client.inc.php), and the stored
     * username and client id can disagree - a panel-side rename, or a service
     * that fell back to tblhosting.username. If the row names a different
     * client, its default_group scopes the FTP and DNS listings to THAT
     * client's records, billing one customer's zones and FTP users to another.
     * The username is not enough to attribute usage, so a mismatch is refused
     * rather than trusted (the memo key is the client id, so the username is
     * otherwise invisible to attribution).
     */
    private function resolveGroupId(int $clientId, string $username): ?int
    {
        if ($username === '') {
            return null;
        }

        $record = $this->session->call('client_get_by_username', ['username' => $username]);
        if (!is_array($record)) {
            return null;
        }

        $recordClientId = QuotaMath::toInt($record['client_id'] ?? 0);
        if ($recordClientId !== $clientId) {
            throw new UsageException(sprintf(
                'ISPConfig username %s resolves to client #%d, not the #%d stored for this '
                    . 'service - the stored username and client id disagree (a panel-side rename, '
                    . 'or a fallback to tblhosting.username). Refusing to scope FTP/DNS counts by '
                    . "another client's group.",
                $username,
                $recordClientId,
                $clientId
            ));
        }

        $groupId = QuotaMath::toInt($record['default_group'] ?? null);

        return $groupId > 0 ? $groupId : null;
    }

    private static function countRows(mixed $response): int
    {
        return is_array($response) ? count($response) : 0;
    }
}
