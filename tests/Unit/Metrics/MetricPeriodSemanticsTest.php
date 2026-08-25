<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Metrics;

use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Metrics\MetricDefinition;
use WHMCS\Module\Server\Ispconfigconnect\Metrics\Provider;
use WHMCS\Module\Server\Ispconfigconnect\Usage\ServiceRef;
use WHMCS\Module\Server\Ispconfigconnect\Usage\UsageParser;

/**
 * Snapshot vs period - the one distinction that can silently multiply an
 * invoice.
 *
 * What developers.whmcs.com/provisioning-modules/usage-metrics/ states:
 *   - TYPE_SNAPSHOT is for non-ephemeral entities where "your remote system is
 *     unlikely to reset this data";
 *   - TYPE_PERIOD_DAY / TYPE_PERIOD_MONTH are for a value that is an
 *     "accumulative measure at the remote system";
 *   - "for monthly items, WHMCS internals will manage one record for each
 *     calendar month; for daily, WHMCS will manage on record for each day";
 *   - "At the end of a service's billing term, any uninvoiced usage for those
 *     periods will be summed and calculations applied".
 *
 * Together: for a PERIOD metric the module reports the remote accumulator's
 * reading for the period IN PROGRESS; WHMCS keeps one record per calendar
 * period, so a later reading in the same month supersedes the earlier one; and
 * summing happens across periods at invoice time, never within one. Reporting
 * a lifetime or year-to-date total into a monthly metric would therefore
 * re-bill every earlier month of the year, every single month.
 *
 * ISPConfig hands us both shapes in ONE response.
 * quota_lib::get_trafficquota_data() (quota_lib.inc.php:94-129) returns, per
 * hostname: 'this_month', 'this_year', 'last_month', 'last_year' and - when
 * $lastdays > 0 - 'lastdays'. Only 'this_month' is a current-calendar-month
 * accumulator (":98, YEAR(traffic_date) = ? AND MONTH(traffic_date) = ?"), so
 * only 'this_month' may ever reach a TYPE_PERIOD_MONTH metric.
 */
#[CoversClass(Provider::class)]
#[CoversClass(UsageParser::class)]
final class MetricPeriodSemanticsTest extends TestCase
{
    private const MIB = 1048576;
    private const GIB = 1073741824;

    private FakeIspconfigServer $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeIspconfigServer();
    }

    private function provider(): Provider
    {
        return new Provider(new SessionManager(new RestClient($this->fake), 'remote', 'secret'));
    }

    /** @return list<string> */
    private function calledMethods(): array
    {
        return array_map(
            static fn (array $entry): string => (string) $entry['method'],
            $this->fake->requestLog
        );
    }

    public function testBandwidthIsTheOnlyPeriodMetricAndItIsMonthly(): void
    {
        $periodMetrics = array_values(array_filter(
            Provider::metrics(),
            static fn (MetricDefinition $m): bool => $m->type !== Provider::TYPE_SNAPSHOT
        ));

        self::assertCount(1, $periodMetrics);
        self::assertSame('bandwidth', $periodMetrics[0]->systemName);
        self::assertSame(Provider::TYPE_PERIOD_MONTH, $periodMetrics[0]->type);
    }

    /**
     * Every storage and count metric is a CURRENT READING of something the
     * panel does not reset, so it is a snapshot. Registering any of them as a
     * period metric would make WHMCS add up twelve monthly readings of the
     * same disk into one annual invoice line.
     */
    public function testEveryStorageAndCountMetricIsRegisteredAsASnapshot(): void
    {
        foreach (Provider::metrics() as $definition) {
            if ($definition->systemName === 'bandwidth') {
                continue;
            }
            self::assertSame(Provider::TYPE_SNAPSHOT, $definition->type, $definition->systemName);
        }
    }

    /**
     * THE pin. The traffic response carries four other period members, all of
     * them larger, and picking any one of them would over-bill. this_year in
     * particular is a year-to-date total: reporting it every month would bill
     * January's gigabytes twelve times.
     */
    public function testOnlyThisMonthIsEverReadOutOfTheTrafficResponse(): void
    {
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [],
            traffic: [
                'example.com' => [
                    'domain_id' => '5',
                    'this_month' => (string) (2 * self::GIB),
                    'this_year' => (string) (90 * self::GIB),
                    'last_month' => (string) (11 * self::GIB),
                    'last_year' => (string) (400 * self::GIB),
                    // Present only when the caller asks for it; the module
                    // asks for lastdays = 0 precisely so this SUM is not run,
                    // but it must be ignored even when a caller changes that.
                    'lastdays' => (string) (7 * self::GIB),
                ],
            ],
        );

        self::assertSame(2 * 1024, $usage->bandwidthUsageMegabytes());
        self::assertSame(2 * self::GIB, $usage->trafficThisMonthBytes);
    }

    /**
     * Same pin, end to end through the double, and across several hostnames so
     * the summation is over this_month values only.
     */
    public function testBandwidthSumsThisMonthAcrossHostnamesAndIgnoresYearToDate(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addWebsite($clientId, 'one.example');
        $this->fake->addWebsite($clientId, 'two.example');

        // thisYear is deliberately an order of magnitude larger than thisMonth.
        $this->fake->setWebTraffic(
            'one.example',
            thisMonthBytes: 3 * self::GIB,
            thisYearBytes: 40 * self::GIB,
            lastMonthBytes: 9 * self::GIB
        );
        $this->fake->setWebTraffic(
            'two.example',
            thisMonthBytes: 1 * self::GIB,
            thisYearBytes: 25 * self::GIB,
            lastMonthBytes: 4 * self::GIB
        );

        $metrics = $this->provider()->tenantUsage(new ServiceRef(101, $clientId, 'svc1'));

        self::assertSame(4 * 1024, $metrics['bandwidth']);
    }

    /**
     * A hostname with no web_traffic row has NO period member at all - the
     * member is absent rather than zero (quota_lib only fills the members the
     * GROUP BY produced). It must contribute nothing, and must not make the
     * sum fall back to some other period.
     */
    public function testAHostnameWithNoTrafficRowContributesNothing(): void
    {
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [],
            traffic: [
                'busy.example' => ['domain_id' => '5', 'this_month' => (string) self::GIB],
                'quiet.example' => ['domain_id' => '6'],
                'also-quiet.example' => ['domain_id' => '7', 'this_year' => (string) (50 * self::GIB)],
            ],
        );

        self::assertSame(1024, $usage->bandwidthUsageMegabytes());
    }

    /**
     * A period value must be IDEMPOTENT within its period: reading twice in
     * one day reports the same month-to-date total, never a doubled one.
     * WHMCS keeps one record per calendar month, so the second reading
     * supersedes the first - which only works if what we send is a reading and
     * not an increment.
     */
    public function testCollectingTwiceInTheSameMonthReportsTheSameTotalNotTwiceIt(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addWebsite($clientId, 'one.example');
        $this->fake->setWebTraffic('one.example', thisMonthBytes: 3 * self::GIB);

        // Two independent providers, i.e. two separate cron runs - no memo to
        // hide behind.
        $first = $this->provider()->tenantUsage(new ServiceRef(101, $clientId, 'svc1'));
        $second = $this->provider()->tenantUsage(new ServiceRef(101, $clientId, 'svc1'));

        self::assertSame(3 * 1024, $first['bandwidth']);
        self::assertSame($first, $second);
    }

    /**
     * The module asks for lastdays = 0 so that get_trafficquota_data() skips
     * its "Last xx Days" SUM entirely ("if (is_int($lastdays) && ($lastdays >
     * 0))", quota_lib.inc.php:123). That is a cost decision, not a correctness
     * one - but if the argument ever stopped being sent, the panel would run a
     * fourth aggregate per sweep for a number nothing reads.
     */
    public function testTheTrafficCallAsksForZeroLastDaysSoThePanelSkipsThatAggregate(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addWebsite($clientId, 'one.example');

        $this->provider()->tenantUsage(new ServiceRef(101, $clientId, 'svc1'));

        $traffic = null;
        foreach ($this->fake->requestLog as $entry) {
            if (($entry['method'] ?? '') === 'trafficquota_get_by_user') {
                $traffic = $entry;
                break;
            }
        }

        self::assertIsArray($traffic);
        self::assertIsArray($traffic['params']);
        self::assertSame(0, $traffic['params']['lastdays']);
    }

    /**
     * A KNOWN CONSEQUENCE of the period model, pinned so it is a decision
     * rather than a surprise.
     *
     * get_trafficquota_data() filters "active = 'y'" (quota_lib.inc.php:83)
     * while get_quota_data() does not filter on active at all. The module
     * suspends a service by setting web_domain.active = 'n'
     * (sites_web_domain_set_status), so a suspended service reports its disk
     * and its object counts as before, but its bandwidth collapses to zero.
     *
     * Because bandwidth is TYPE_PERIOD_MONTH and WHMCS keeps only the latest
     * reading for the calendar month, a mid-month suspension REPLACES that
     * month's accumulated traffic with 0 instead of freezing it - the customer
     * is not billed for what they used before the suspension. The module
     * under-reports rather than over-reports, which is the safe direction, but
     * an operator billing on bandwidth needs to know it happens.
     */
    public function testSuspendingAServiceZeroesItsBandwidthWhileDiskAndCountsSurvive(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $liveId = $this->fake->addWebsite($clientId, 'live.example');
        $suspendedId = $this->fake->addWebsite($clientId, 'off.example', active: 'n');
        $this->fake->setDiskUsage('web' . $liveId, usedKilobytes: 262144);       // 256 MiB
        $this->fake->setDiskUsage('web' . $suspendedId, usedKilobytes: 262144);  // 256 MiB
        $this->fake->setWebTraffic('live.example', thisMonthBytes: 2 * self::GIB);
        $this->fake->setWebTraffic('off.example', thisMonthBytes: 5 * self::GIB);

        $metrics = $this->provider()->tenantUsage(new ServiceRef(101, $clientId, 'svc1'));

        // Only the ACTIVE site's traffic is reported...
        self::assertSame(2 * 1024, $metrics['bandwidth']);
        // ...while both sites still count, and both still hold their disk.
        self::assertSame(2, $metrics['websites']);
        self::assertSame(512, $metrics['diskusage']);
    }

    /**
     * The complement: a snapshot metric is unaffected by how many times it is
     * collected, which is what makes TYPE_SNAPSHOT the right registration for
     * everything except bandwidth.
     */
    public function testSnapshotMetricsAreUnchangedByRepeatedCollection(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $siteId = $this->fake->addWebsite($clientId, 'one.example');
        $this->fake->setDiskUsage('web' . $siteId, usedKilobytes: 524288);
        $this->fake->addMailUser($clientId, 'a@one.example', usedBytes: 128 * self::MIB);
        $this->fake->addDatabase($clientId, 'c1_one');
        $this->fake->setDatabaseSize('c1_one', bytes: 64 * self::MIB);

        $runs = [];
        for ($i = 0; $i < 3; $i++) {
            $runs[] = $this->provider()->tenantUsage(new ServiceRef(101, $clientId, 'svc1'));
        }

        self::assertSame(512, $runs[0]['diskusage']);
        self::assertSame(128, $runs[0]['mailquota']);
        self::assertSame(64, $runs[0]['databasequota']);
        self::assertSame($runs[0], $runs[1]);
        self::assertSame($runs[1], $runs[2]);
        self::assertNotSame([], $this->calledMethods());
    }
}
