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
     * The LIMIT comes from the web_domain traffic_quota column (megabytes) on
     * the disk-quota rows, which is where the panel keeps it.
     *
     * @param list<array<string, mixed>> $trafficRows
     * @param list<array<string, mixed>> $siteRows
     * @return array{used: int, limit: int|null}
     */
    private static function sumBandwidth(array $trafficRows, array $siteRows): array
    {
        $used = 0;
        foreach ($trafficRows as $row) {
            $used += QuotaMath::toInt($row['this_month'] ?? 0);
        }

        $limitBytes = 0;
        $unlimited = $siteRows === [];
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
