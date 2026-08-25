<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Usage;

/**
 * Pure translation of ISPConfig's quota responses into a ClientUsage.
 * No I/O, no state - every wire fact it encodes is cited inline against
 * interface/lib/classes/quota_lib.inc.php of 3.3.1p1.
 *
 * Aggregation rule for limits: a client's limit is the SUM of its records'
 * limits, and any single unlimited record makes the whole thing unlimited
 * (the sum of "1 GB" and "no limit" is "no limit"). An empty record set is
 * unlimited too - there is no limit to report when there is nothing limited.
 *
 * Attribution: the module provisions exactly one ISPConfig client per WHMCS
 * service (Provision\Recipe), so a client's totals ARE the service's totals.
 */
final class UsageParser
{
    /**
     * @param mixed $diskQuota     quota_get_by_user response
     * @param mixed $traffic       trafficquota_get_by_user response
     * @param mixed $mailQuota     mailquota_get_by_user response
     * @param mixed $databaseQuota databasequota_get_by_user response
     * @param mixed $ftpUsers      sites_ftp_user_get response (filtered list)
     * @param mixed $dnsZones      dns_zone_get response (filtered list)
     */
    public static function parse(
        int $clientId,
        mixed $diskQuota,
        mixed $traffic,
        mixed $mailQuota = null,
        mixed $databaseQuota = null,
        mixed $ftpUsers = null,
        mixed $dnsZones = null,
    ): ClientUsage {
        $siteRows = self::rows($diskQuota);
        $mailRows = self::rows($mailQuota);
        $databaseRows = self::rows($databaseQuota);

        $disk = self::sumDisk($siteRows);
        $bandwidth = self::sumBandwidth(self::rows($traffic), $siteRows);
        $mail = self::sumMail($mailRows);
        $database = self::sumDatabase($databaseRows);

        $groupIds = self::sysGroupIds($siteRows, $mailRows, $databaseRows);

        return new ClientUsage(
            clientId: $clientId,
            diskUsedBytes: $disk['used'],
            diskLimitBytes: $disk['limit'],
            trafficThisMonthBytes: $bandwidth['used'],
            trafficLimitBytes: $bandwidth['limit'],
            mailUsedBytes: $mail['used'],
            mailQuotaBytes: $mail['limit'],
            databaseUsedBytes: $database['used'],
            databaseQuotaBytes: $database['limit'],
            websiteCount: count($siteRows),
            mailboxCount: count($mailRows),
            databaseCount: count($databaseRows),
            ftpUserCount: count(self::rows($ftpUsers)),
            dnsZoneCount: count(self::rows($dnsZones)),
            sysGroupIds: $groupIds,
            // Exactly one group is the only attributable answer: zero means no
            // record carried it, several mean the client's group list spanned
            // more than one group - a reseller id, whose
            // clientid_to_groups_list() includes its sub-clients' groups
            // (functions.inc.php; see Collector's guard).
            sysGroupId: count($groupIds) === 1 ? $groupIds[0] : null,
        );
    }

    /**
     * Every distinct sys_groupid across the given record lists, ascending.
     *
     * @param list<array<string, mixed>> ...$recordLists
     * @return list<int>
     */
    public static function sysGroupIds(array ...$recordLists): array
    {
        $ids = [];
        foreach ($recordLists as $records) {
            foreach ($records as $record) {
                if (!isset($record['sys_groupid'])) {
                    continue;
                }
                $id = QuotaMath::toInt($record['sys_groupid']);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * quota_lib::get_quota_data(): one row per type='vhost' web_domain.
     * used_raw is BYTES (the KILOBYTE monitor value times 1024) and is present
     * even with no monitor data, where it lands at 0. The LIMIT comes from the
     * hd_quota COLUMN rather than from soft_raw: hd_quota is the configured
     * truth and survives a monitor cron that has not run yet, while soft_raw
     * only exists once repquota has reported.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{used: int, limit: int|null}
     */
    private static function sumDisk(array $rows): array
    {
        $used = 0;
        $limitBytes = 0;
        $unlimited = $rows === [];

        foreach ($rows as $row) {
            $used += array_key_exists('used_raw', $row)
                ? QuotaMath::toInt($row['used_raw'])
                : QuotaMath::toInt($row['used'] ?? 0) * 1024;

            $inMegabytes = array_key_exists('hd_quota', $row) && $row['hd_quota'] !== null;
            $quota = $inMegabytes ? $row['hd_quota'] : ($row['soft_raw'] ?? null);
            if (QuotaMath::isUnlimited($quota)) {
                $unlimited = true;

                continue;
            }
            // hd_quota is megabytes; soft_raw, if that is what we fell back to,
            // is already bytes.
            $limitBytes += $inMegabytes
                ? QuotaMath::megabytesToBytes($quota)
                : QuotaMath::toInt($quota);
        }

        return ['used' => max(0, $used), 'limit' => $unlimited ? null : $limitBytes];
    }

    /**
     * quota_lib::get_trafficquota_data(): a map keyed by DOMAIN NAME, each
     * member holding SUM(web_traffic.traffic_bytes) per period in BYTES. The
     * period members are ABSENT when no traffic row exists, so 'this_month'
     * must never be assumed. It covers vhost, vhostsubdomain AND vhostalias
     * records while get_quota_data() covers vhost only - summing the whole map
     * is what attributes a service's subdomains to that service.
     *
     * ONLY 'this_month' is ever read, and that choice is the difference
     * between a correct invoice and a compounding one. The same response also
     * carries 'this_year', 'last_month', 'last_year' and (when lastdays > 0)
     * 'lastdays'. Bandwidth is registered as TYPE_PERIOD_MONTH, for which
     * WHMCS "will manage one record for each calendar month" and, on every
     * collection, "the value of these metrics will overwrite any previous data
     * for the respective time period"
     * (developers.whmcs.com/provisioning-modules/usage-metrics/) - so the
     * value has to be the accumulator's reading FOR THE MONTH IN PROGRESS.
     * 'this_month' is exactly that: quota_lib.inc.php:98 filters
     * "YEAR(traffic_date) = ? AND MONTH(traffic_date) = ?" against the current
     * date, so it restarts by itself at the month boundary. Feeding
     * 'this_year' into the same metric would re-bill every earlier month of
     * the year, every month.
     *
     * No de-duplication is needed across the map: 200-logfiles.inc.php writes
     * one web_traffic row per (hostname, day) from that host's OWN access log,
     * and a vhostsubdomain/vhostalias logs into its own log folder - so a
     * client's domains never account for each other's bytes.
     *
     * NOTE an asymmetry worth knowing at invoice time: this query filters
     * "active = 'y'" (quota_lib.inc.php:83) while get_quota_data() does not.
     * A suspended service - the module sets web_domain.active = 'n' via
     * sites_web_domain_set_status - therefore keeps reporting disk but drops
     * to zero bandwidth, and because WHMCS keeps only the latest reading for
     * the month, a mid-month suspension erases that month's traffic instead of
     * freezing it. Pinned by MetricPeriodSemanticsTest.
     *
     * The LIMIT comes from the web_domain traffic_quota column (megabytes) on
     * the disk-quota rows, which is where the panel keeps it - and that is
     * where usage and limit stop covering the same objects. The traffic query
     * spans three domain types (:83), the disk query one (:17), so a client
     * with a quota'd website plus a subdomain or an alias gets every domain's
     * BYTES counted against only the website's QUOTA.
     *
     * ISPConfig never enforces it that way: 300-quota_notify.inc.php:86 walks
     * "(traffic_quota > 0 ...) AND (type='vhost' OR 'vhostsubdomain' OR
     * 'vhostalias')" and compares each domain's own SUM(traffic_bytes) against
     * its OWN traffic_quota (:96), each type carrying its own column. Summing
     * the bytes of a domain whose quota is invisible into a limit built from
     * the others therefore reports a limit that is too tight, and WHMCS would
     * bill overage on bandwidth the panel would never have charged for.
     *
     * So the limit is only reported when every domain that CONTRIBUTED BYTES
     * has a quota row here. Otherwise it is unknown, which is reported the same
     * way as unlimited: ServiceUsage::toHostingColumns() then omits bwlimit
     * entirely and the WHMCS admin's own configured limit survives the sweep -
     * the same rule that already protects it from a silent 0. Under-stating a
     * limit is an over-charge; declining to state one is not.
     *
     * A traffic entry with NO bytes is ignored for this test: quota_lib seeds
     * $traffic_data[$domain]['domain_id'] for every active domain before it
     * runs the sums (:90), so entries with no period member are the normal
     * state of a domain that saw no traffic, and nothing is being billed for
     * them.
     *
     * @param list<array<string, mixed>> $trafficRows
     * @param list<array<string, mixed>> $siteRows
     * @return array{used: int, limit: int|null}
     */
    private static function sumBandwidth(array $trafficRows, array $siteRows): array
    {
        $quotedDomainIds = [];
        foreach ($siteRows as $row) {
            $domainId = QuotaMath::toInt($row['domain_id'] ?? 0);
            if ($domainId > 0) {
                $quotedDomainIds[$domainId] = true;
            }
        }

        $used = 0;
        // True once bytes are billed for a domain whose traffic_quota is not
        // visible in $siteRows - including one that carried no domain_id at all.
        $unmeasuredDomainBilled = false;
        foreach ($trafficRows as $row) {
            $bytes = QuotaMath::toInt($row['this_month'] ?? 0);
            if ($bytes <= 0) {
                continue;
            }
            $used += $bytes;
            if (!isset($quotedDomainIds[QuotaMath::toInt($row['domain_id'] ?? 0)])) {
                $unmeasuredDomainBilled = true;
            }
        }

        $limitBytes = 0;
        $unlimited = $siteRows === [] || $unmeasuredDomainBilled;
        foreach ($siteRows as $row) {
            if (QuotaMath::isUnlimited($row['traffic_quota'] ?? null)) {
                $unlimited = true;

                continue;
            }
            $limitBytes += QuotaMath::megabytesToBytes($row['traffic_quota']);
        }

        return ['used' => max(0, $used), 'limit' => $unlimited ? null : $limitBytes];
    }

    /**
     * quota_lib::get_mailquota_data(): mail_user rows where BOTH quota and
     * used are BYTES (interface/web/mail/user_quota_stats.php divides quota by
     * 1048576 to show MB; the email_quota monitor writes doveadm kB * 1024).
     * quota == 0 is unlimited - the $readable branch that rewrites it to -1 is
     * never taken over the remote API, which passes $readable = false.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{used: int, limit: int|null}
     */
    private static function sumMail(array $rows): array
    {
        $used = 0;
        $limitBytes = 0;
        $unlimited = $rows === [];

        foreach ($rows as $row) {
            $used += array_key_exists('used_raw', $row)
                ? QuotaMath::toInt($row['used_raw'])
                : QuotaMath::toInt($row['used'] ?? 0);

            $quota = $row['quota_raw'] ?? $row['quota'] ?? null;
            if (QuotaMath::isUnlimited($quota)) {
                $unlimited = true;

                continue;
            }
            $limitBytes += QuotaMath::toInt($quota);
        }

        return ['used' => max(0, $used), 'limit' => $unlimited ? null : $limitBytes];
    }

    /**
     * quota_lib::get_databasequota_data(): web_database rows where used_raw is
     * the information_schema data_length+index_length sum in BYTES, and
     * database_quota_raw is database_quota * 1000 * 1000 - DECIMAL megabytes,
     * an ISPConfig inconsistency (the server-side over-quota check in
     * 100-monitor_database_size.inc.php uses 1024*1024 for the same column).
     * database_quota_raw is used as returned so the module agrees with what the
     * panel shows the customer; -1 is unlimited.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{used: int, limit: int|null}
     */
    private static function sumDatabase(array $rows): array
    {
        $used = 0;
        $limitBytes = 0;
        $unlimited = $rows === [];

        foreach ($rows as $row) {
            $used += QuotaMath::toInt($row['used_raw'] ?? 0);

            $quota = $row['database_quota_raw'] ?? null;
            if ($quota === null && array_key_exists('database_quota', $row)) {
                $quota = QuotaMath::isUnlimited($row['database_quota'])
                    ? -1
                    : QuotaMath::toInt($row['database_quota']) * 1000 * 1000;
            }
            if (QuotaMath::isUnlimited($quota)) {
                $unlimited = true;

                continue;
            }
            $limitBytes += QuotaMath::toInt($quota);
        }

        return ['used' => max(0, $used), 'limit' => $unlimited ? null : $limitBytes];
    }

    /**
     * Normalise any quota response into a list of records. queryAllRecords()
     * returns [] for no rows (json_encoded as an empty JSON array), the
     * traffic response is a domain-keyed MAP rather than a list, and a
     * permission-denied path can even return false - all three land here.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $response): array
    {
        if (!is_array($response)) {
            return [];
        }

        $rows = [];
        foreach ($response as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
