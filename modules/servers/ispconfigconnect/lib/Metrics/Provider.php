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
    /** Non-ephemeral resources - the remote system never resets them. */
    public const TYPE_SNAPSHOT = 'snapshot';

    /**
     * Accumulative and reset monthly. ISPConfig's web_traffic is summed per
     * calendar month by quota_lib::get_trafficquota_data(), which is exactly
     * WHMCS's TYPE_PERIOD_MONTH contract: each collection overwrites that
     * month's record.
     */
    public const TYPE_PERIOD_MONTH = 'period_month';

    /** Maps to \WHMCS\UsageBilling\Metrics\Units\MegaBytes. */
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
        $usage = [];

        foreach ($services as $service) {
            if ($service->username === '') {
                // WHMCS matches tenants by username; an entry we cannot key is
                // an entry it cannot attribute.
                $this->failures[$service->serviceId] = 'no ISPConfig username stored for this service';

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

    /** @return array<string, int> */
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
