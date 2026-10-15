<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Metrics;

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\IspconfigException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LockoutException;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Usage\ClientUsage;
use WHMCS\Module\Server\Ispconfigconnect\Usage\Collector;
use WHMCS\Module\Server\Ispconfigconnect\Usage\ServiceRef;

/**
 * The data behind ispconfigconnect_MetricProvider
 * (developers.whmcs.com/provisioning-modules/usage-metrics/).
 *
 * WHMCS expects an object implementing
 * \WHMCS\UsageBilling\Contracts\Metrics\ProviderInterface, built from its own
 * Metric/Usage/Units classes. Those are ionCube-encoded WHMCS core and cannot
 * be referenced from lib code, so this class produces the equivalent PLAIN
 * data and the entry file adapts it:
 *
 *   metrics()      -> list<MetricDefinition>            -> Metric objects
 *   tenantUsage()  -> array<systemName, int>            -> $metric->withUsage(new Usage($v))
 *   usage()        -> array<tenantKey, array<systemName, int>>
 *
 * The tenant key is the service username, which is what WHMCS matches
 * tenants on and what the documented sample keys usage() by.
 *
 * metrics() is STATIC and does no I/O on purpose: WHMCS invokes it in PRODUCT
 * contexts where no service and no server are in scope, and the documentation
 * requires it to return the same list for every server using the module.
 */
final class Provider
{
    /**
     * MetricInterface::TYPE_SNAPSHOT. The doc's test for it is about the
     * REMOTE system, not about us: it applies to non-ephemeral entities where
     * "your remote system is unlikely to reset this data".
     *
     * The reported value is therefore a CURRENT READING - what the tenant is
     * using at the moment of collection - and WHMCS keeps the latest one.
     * Collecting twice in a day must not double anything, which is why every
     * snapshot value below is a plain SUM over the client's records rather
     * than anything cumulative.
     */
    public const TYPE_SNAPSHOT = 'snapshot';

    /**
     * MetricInterface::TYPE_PERIOD_MONTH, for a metric that is an
     * "accumulative measure at the remote system" reset monthly by that
     * system. Per the doc: "for monthly items, WHMCS internals will manage one
     * record for each calendar month; for daily, WHMCS will manage on record
     * for each day", and "At the end of a service's billing term, any
     * uninvoiced usage for those periods will be summed and calculations
     * applied".
     *
     * CONFIRMED by those sentences: WHMCS holds one record per calendar month,
     * and summation happens ACROSS periods at invoice time.
     *
     * ALSO CONFIRMED, and the single most load-bearing sentence in this module:
     * "When data is collected by WHMCS, the value of these metrics will
     * overwrite any previous data for the respective time period." Within a
     * month the later reading REPLACES the earlier one; it is never added to
     * it. So what we send must be the accumulator's CURRENT READING for the
     * month in progress - a month-to-date total - and never a delta since the
     * last collection.
     *
     * Do not "fix" this into a delta. Sending deltas against a column WHMCS
     * overwrites would report only the bytes since the previous cron run and
     * under-bill every customer by the rest of the month; sending a lifetime
     * total would re-bill every earlier month, every month. Month-to-date is
     * the only value that matches the contract, and collecting twice in one
     * day must leave the figure unchanged.
     *
     * ISPConfig's web_traffic matches that shape exactly.
     * quota_lib::get_trafficquota_data() computes 'this_month' as
     * "SELECT hostname, SUM(traffic_bytes) FROM web_traffic WHERE
     * YEAR(traffic_date) = ? AND MONTH(traffic_date) = ?"
     * (quota_lib.inc.php:98) - a month-to-date total against the CALENDAR
     * month, which restarts on its own at the month boundary because the year
     * and month predicates move. The rows behind it are written per hostname
     * per day by 200-logfiles.inc.php.
     *
     * The reason this constant is the highest-risk line in the module: the
     * same response also carries 'this_year' (year to date), 'last_month' and
     * 'last_year', all computed by the same SUM with different date
     * predicates. Reporting 'this_year' into a PERIOD_MONTH metric would
     * re-bill every earlier month of the year, every month. UsageParser reads
     * 'this_month' and nothing else, and MetricPeriodSemanticsTest pins it.
     */
    public const TYPE_PERIOD_MONTH = 'period_month';

    /**
     * The unit strings the entry file maps onto \WHMCS\UsageBilling\Metrics\Units.
     * That namespace ships Bytes, MegaBytes, GigaBytes, Accounts and Domains;
     * each takes the display label as its constructor argument, as in the
     * documented sample's "new Accounts('Mailboxes')" - which is what
     * MetricDefinition::$unitLabel carries.
     */
    public const UNIT_MEGABYTES = 'megabytes';
    /** Maps to \WHMCS\UsageBilling\Metrics\Units\Accounts. */
    public const UNIT_ACCOUNTS = 'accounts';
    /** Maps to \WHMCS\UsageBilling\Metrics\Units\Domains. */
    public const UNIT_DOMAINS = 'domains';

    private readonly Collector $collector;

    /** @var array<int, string> WHMCS service id => reason */
    private array $failures = [];

    public function __construct(SessionManager $session, ?Collector $collector = null)
    {
        $this->collector = $collector ?? new Collector($session);
    }

    /**
     * The metric catalogue. Stable system names - they key the usage records
     * WHMCS stores, so renaming one orphans its history.
     *
     * PROVENANCE, verified against ISPConfig 3.3.1p1 source. "stored" is the
     * unit of the column/member ISPConfig puts on the wire; "emitted" is what
     * this class hands WHMCS. Every megabyte here is BINARY (2^20) - ISPConfig
     * treats its own MB that way (300-quota_notify.inc.php compares
     * traffic_quota against traffic_bytes/1024/1024; apache2_plugin.inc.php:981
     * turns hd_quota into hd_quota*1024 one-kilobyte quota blocks).
     *
     *  metric        source (quota_lib.inc.php)     member       stored  emitted
     *  ------------- ------------------------------ ------------ ------- -------
     *  diskusage     get_quota_data                 used_raw     bytes   MB
     *                (repquota KB * 1024, :43; the harddisk_quota monitor
     *                writes repquota/du 1K blocks, 100-monitor_hd_quota)
     *  bandwidth     get_trafficquota_data          this_month   bytes   MB
     *                (SUM(web_traffic.traffic_bytes) for the calendar month,
     *                :98 - month-to-date, NOT lifetime)
     *  mailquota     get_mailquota_data             used_raw     bytes   MB
     *                (:246, straight from the email_quota monitor, which
     *                writes doveadm kB * 1024, 100-monitor_email_quota)
     *  databasequota get_databasequota_data         used_raw     bytes   MB
     *                (:302, information_schema data_length+index_length via
     *                db_mysql::getDatabaseSize)
     *  websites      get_quota_data                 row count    rows    Accounts
     *                (web_domain WHERE type='vhost' ONLY, :17 - a
     *                vhostsubdomain or vhostalias is not a website here)
     *  mailboxes     get_mailquota_data             row count    rows    Accounts
     *                (mail_user, :223)
     *  databases     get_databasequota_data         row count    rows    Accounts
     *                (web_database, :289)
     *  ftpusers      sites_ftp_user_get             row count    rows    Accounts
     *                (filtered on the client's sys_groupid)
     *  dnszones      dns_zone_get                   row count    rows    Domains
     *                (filtered on the client's sys_groupid)
     *
     * Every entity count is a count of DISTINCT records the panel returned for
     * one client, so a client owning several websites or mailboxes is summed,
     * never multiplied: quota_lib returns one row per object and each object
     * belongs to exactly one client group.
     *
     * @return list<MetricDefinition>
     */
    public static function metrics(): array
    {
        return [
            new MetricDefinition('diskusage', 'Disk Usage', self::TYPE_SNAPSHOT, self::UNIT_MEGABYTES, 'MB'),
            new MetricDefinition('bandwidth', 'Bandwidth', self::TYPE_PERIOD_MONTH, self::UNIT_MEGABYTES, 'MB'),
            new MetricDefinition('mailquota', 'Mailbox Storage', self::TYPE_SNAPSHOT, self::UNIT_MEGABYTES, 'MB'),
            new MetricDefinition('databasequota', 'Database Storage', self::TYPE_SNAPSHOT, self::UNIT_MEGABYTES, 'MB'),
            new MetricDefinition('websites', 'Websites', self::TYPE_SNAPSHOT, self::UNIT_ACCOUNTS, 'Websites'),
            new MetricDefinition('mailboxes', 'Mailboxes', self::TYPE_SNAPSHOT, self::UNIT_ACCOUNTS, 'Mailboxes'),
            new MetricDefinition('databases', 'Databases', self::TYPE_SNAPSHOT, self::UNIT_ACCOUNTS, 'Databases'),
            new MetricDefinition('ftpusers', 'FTP Users', self::TYPE_SNAPSHOT, self::UNIT_ACCOUNTS, 'FTP Users'),
            new MetricDefinition('dnszones', 'DNS Zones', self::TYPE_SNAPSHOT, self::UNIT_DOMAINS, 'DNS Zones'),
        ];
    }

    /**
     * Every metric's current value for one service, keyed by system name and
     * ordered exactly like metrics().
     *
     * @return array<string, int>
     * @throws IspconfigException on a failed read - the single-service context
     *         has a caller who can surface the error, unlike the sweep below
     */
    public function tenantUsage(ServiceRef $service): array
    {
        return self::values($this->collector->metricsFor($service->clientId, $service->username));
    }

    /**
     * Usage for every service on this server, keyed by tenant.
     *
     * @param list<ServiceRef> $services
     * @return array<string, array<string, int>>
     * @throws LockoutException when the panel locks this IP out mid-sweep
     */
    public function usage(array $services): array
    {
        // WHMCS keys tenant usage by the account username, so two services that
        // share one are indistinguishable to it: keying both under the same
        // username would let the last write silently hand one service's usage
        // to the other. Find the collisions up front and fail EVERY service
        // involved rather than report a wrong figure for any of them. A shared
        // username reaches here through the tblhosting.username fallback, or
        // when two services are linked to one ISPConfig client.
        $serviceIdsByUsername = [];
        foreach ($services as $service) {
            if ($service->username !== '') {
                $serviceIdsByUsername[$service->username][] = $service->serviceId;
            }
        }

        $usage = [];

        foreach ($services as $service) {
            if ($service->username === '') {
                // WHMCS matches tenants by username; an entry we cannot key is
                // an entry it cannot attribute.
                $this->failures[$service->serviceId] = 'no ISPConfig username stored for this service';

                continue;
            }
            if (count($serviceIdsByUsername[$service->username]) > 1) {
                $others = array_values(array_filter(
                    $serviceIdsByUsername[$service->username],
                    static fn (int $id): bool => $id !== $service->serviceId
                ));
                $this->failures[$service->serviceId] = sprintf(
                    'ISPConfig username %s is shared with service(s) %s - WHMCS keys usage by '
                        . 'username, so it cannot be attributed to one service and no usage was '
                        . 'reported for any of them.',
                    $service->username,
                    $others === [] ? '(itself, duplicated)' : implode(', ', $others)
                );

                continue;
            }
            if ($service->clientId <= 0) {
                $this->failures[$service->serviceId] = 'no ISPConfig client id stored for this service';

                continue;
            }

            try {
                $usage[$service->username] = $this->tenantUsage($service);
            } catch (LockoutException $lockout) {
                // The circuit is open for the rest of this request; continuing
                // would only extend the panel's 5-minute lockout window.
                throw $lockout;
            } catch (IspconfigException $failure) {
                $this->failures[$service->serviceId] = $failure->getMessage();
            }
        }

        return $usage;
    }

    /**
     * Services skipped by usage() so far, with the reason. Accumulates across
     * sweeps on the same instance so a caller that batches keeps every reason.
     *
     * @return array<int, string> WHMCS service id => reason
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * @return array<string, int>
     *
     * LIMITATION (see UsageParser::sumBandwidth): 'bandwidth' is the month's
     * ACTIVE-domain traffic as the panel reports it right now. A mid-month
     * suspension or ISPConfig over-traffic lock drops inactive domains from
     * that sum, and WHMCS overwrites the month's period record with whatever we
     * return - so the figure can fall back towards 0 for the customer who went
     * furthest over quota. The UsageUpdate path guards tblhosting.bwusage with
     * a current-month high-water mark; the metric path cannot do the same here
     * because WHMCS's stored period value is never handed back to this layer to
     * max() against. The value is at least clamped to >= 0 and never negative.
     */
    private static function values(ClientUsage $usage): array
    {
        return [
            'diskusage' => $usage->diskUsageMegabytes(),
            'bandwidth' => $usage->bandwidthUsageMegabytes(),
            'mailquota' => $usage->mailUsageMegabytes(),
            'databasequota' => $usage->databaseUsageMegabytes(),
            'websites' => $usage->websiteCount,
            'mailboxes' => $usage->mailboxCount,
            'databases' => $usage->databaseCount,
            'ftpusers' => $usage->ftpUserCount,
            'dnszones' => $usage->dnsZoneCount,
        ];
    }
}
