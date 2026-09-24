<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Usage;

use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LockoutException;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Usage\ClientUsage;
use WHMCS\Module\Server\Ispconfigconnect\Usage\Collector;
use WHMCS\Module\Server\Ispconfigconnect\Usage\QuotaMath;
use WHMCS\Module\Server\Ispconfigconnect\Usage\ServiceRef;
use WHMCS\Module\Server\Ispconfigconnect\Usage\ServiceUsage;
use WHMCS\Module\Server\Ispconfigconnect\Usage\Updater;
use WHMCS\Module\Server\Ispconfigconnect\Usage\UsageParser;
use WHMCS\Module\Server\Ispconfigconnect\Usage\UsageReport;

/**
 * Updater runs through the full stack - Updater -> Collector -> SessionManager
 * -> RestClient -> FakeIspconfigServer - so every fixture here is a real
 * remote/json.php round trip against the 3.3.1p1 behavioral double.
 */
#[CoversClass(Updater::class)]
#[CoversClass(Collector::class)]
#[CoversClass(ServiceRef::class)]
#[CoversClass(ServiceUsage::class)]
#[CoversClass(UsageReport::class)]
#[CoversClass(ClientUsage::class)]
#[CoversClass(UsageParser::class)]
#[CoversClass(QuotaMath::class)]
final class UpdaterTest extends TestCase
{
    private FakeIspconfigServer $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeIspconfigServer();
    }

    private function updater(): Updater
    {
        return new Updater(new SessionManager(new RestClient($this->fake), 'remote', 'secret'));
    }

    /** @return list<string> */
    private function calledMethods(): array
    {
        return array_map(
            static fn (array $entry): string => (string) $entry['method'],
            $this->fake->requestLog
        );
    }

    public function testConvertsPanelQuotasIntoTheMegabytesWhmcsStores(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $domainId = $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: 1024, trafficQuotaMegabytes: 10240);
        $this->fake->setDiskUsage('web' . $domainId, usedKilobytes: 524288);
        $this->fake->setWebTraffic('one.example', thisMonthBytes: 2147483648);

        $report = $this->updater()->collect([new ServiceRef(101, $clientId, 'svc1')]);

        self::assertSame([], $report->failures);
        self::assertCount(1, $report->usage);
        $usage = $report->usage[0];
        self::assertSame(101, $usage->serviceId);
        self::assertSame($clientId, $usage->clientId);
        self::assertSame(512, $usage->diskUsageMegabytes);
        self::assertSame(1024, $usage->diskLimitMegabytes);
        self::assertSame(2048, $usage->bandwidthUsageMegabytes);
        self::assertSame(10240, $usage->bandwidthLimitMegabytes);
        self::assertFalse($usage->diskUnlimited);
        self::assertFalse($usage->bandwidthUnlimited);
    }

    public function testHostingColumnsUseTheTblhostingColumnNames(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $domainId = $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: 512, trafficQuotaMegabytes: 1024);
        $this->fake->setDiskUsage('web' . $domainId, usedKilobytes: 262144);
        $this->fake->setWebTraffic('one.example', thisMonthBytes: 1048576);

        $report = $this->updater()->collect([new ServiceRef(101, $clientId, 'svc1')]);

        self::assertSame(
            ['diskusage' => 256, 'disklimit' => 512, 'bwusage' => 1, 'bwlimit' => 1024],
            $report->usage[0]->toHostingColumns()
        );
    }

    public function testUsageUpdateOnlyCallsTheTwoQuotaFunctionsItNeeds(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addWebsite($clientId, 'one.example');

        $this->updater()->collect([new ServiceRef(101, $clientId, 'svc1')]);

        self::assertSame(
            ['login', 'quota_get_by_user', 'trafficquota_get_by_user'],
            $this->calledMethods()
        );
    }

    public function testUnlimitedPanelQuotasBecomeZeroLimitsWithAnUnlimitedFlag(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $domainId = $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: -1, trafficQuotaMegabytes: -1);
        $this->fake->setDiskUsage('web' . $domainId, usedKilobytes: 1048576);

        $report = $this->updater()->collect([new ServiceRef(101, $clientId, 'svc1')]);

        self::assertSame(1024, $report->usage[0]->diskUsageMegabytes);
        self::assertSame(0, $report->usage[0]->diskLimitMegabytes);
        self::assertTrue($report->usage[0]->diskUnlimited);
        self::assertTrue($report->usage[0]->bandwidthUnlimited);
    }

    public function testUnlimitedQuotasOmitTheLimitColumnsFromTheHostingUpdate(): void
    {
        // The module provisions every website with hd_quota = -1 and
        // traffic_quota = -1 (Provision\Mapper), so "unlimited" is the NORMAL
        // state, not an edge case. WHMCS has no unlimited sentinel for
        // tblhosting.disklimit/bwlimit - the admin configures those limits in
        // the product - so the sweep must leave them alone rather than write a
        // silent 0 that disables overage billing and the usage bars.
        $clientId = $this->fake->addClient('svc1');
        $domainId = $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: -1, trafficQuotaMegabytes: -1);
        $this->fake->setDiskUsage('web' . $domainId, usedKilobytes: 1048576);
        $this->fake->setWebTraffic('one.example', thisMonthBytes: 2147483648);

        $report = $this->updater()->collect([new ServiceRef(101, $clientId, 'svc1')]);

        self::assertSame(
            ['diskusage' => 1024, 'bwusage' => 2048],
            $report->usage[0]->toHostingColumns()
        );
    }

    public function testAMixedQuotaKeepsOnlyTheFiniteLimitColumn(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: 2048, trafficQuotaMegabytes: -1);

        $report = $this->updater()->collect([new ServiceRef(101, $clientId, 'svc1')]);

        $columns = $report->usage[0]->toHostingColumns();
        self::assertSame(2048, $columns['disklimit']);
        self::assertArrayNotHasKey('bwlimit', $columns);
    }

    public function testClientWhoseWebsiteWasNeverCreatedReportsZeroes(): void
    {
        // A mail-only or DNS-only product: quota_get_by_user returns [] and
        // trafficquota_get_by_user returns [] - not an error. The collector
        // confirms the client still EXISTS (client_get) before accepting the
        // empty answer, because a deleted client produces the identical
        // responses.
        $clientId = $this->fake->addClient('svc1');

        $report = $this->updater()->collect([new ServiceRef(101, $clientId, 'svc1')]);

        self::assertSame([], $report->failures);
        self::assertSame(0, $report->usage[0]->diskUsageMegabytes);
        self::assertSame(0, $report->usage[0]->bandwidthUsageMegabytes);
        self::assertTrue($report->usage[0]->diskUnlimited);
        self::assertContains('client_get', $this->calledMethods());
    }

    public function testMissingMonitorDataIsNotAnError(): void
    {
        // The harddisk_quota cron has not run for this brand new site, so no
        // monitor entry exists for its system_user.
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: 2048);

        $report = $this->updater()->collect([new ServiceRef(101, $clientId, 'svc1')]);

        self::assertSame([], $report->failures);
        self::assertSame(0, $report->usage[0]->diskUsageMegabytes);
        self::assertSame(2048, $report->usage[0]->diskLimitMegabytes);
    }

    public function testMultipleServicesAreCollectedIndependently(): void
    {
        $first = $this->fake->addClient('svc1');
        $firstDomain = $this->fake->addWebsite($first, 'one.example', hdQuotaMegabytes: 1024);
        $this->fake->setDiskUsage('web' . $firstDomain, usedKilobytes: 102400);

        $second = $this->fake->addClient('svc2');
        $secondDomain = $this->fake->addWebsite($second, 'two.example', hdQuotaMegabytes: 4096);
        $this->fake->setDiskUsage('web' . $secondDomain, usedKilobytes: 204800);

        $report = $this->updater()->collect([
            new ServiceRef(101, $first, 'svc1'),
            new ServiceRef(102, $second, 'svc2'),
        ]);

        self::assertCount(2, $report->usage);
        self::assertSame(100, $report->usage[0]->diskUsageMegabytes);
        self::assertSame(200, $report->usage[1]->diskUsageMegabytes);
    }

    public function testTwoServicesSharingAnIspconfigClientReuseOneFetch(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: 512);

        $report = $this->updater()->collect([
            new ServiceRef(101, $clientId, 'svc1'),
            new ServiceRef(102, $clientId, 'svc1'),
        ]);

        self::assertCount(2, $report->usage);
        self::assertSame(
            ['login', 'quota_get_by_user', 'trafficquota_get_by_user'],
            $this->calledMethods()
        );
    }

    public function testAServiceWithoutAnIspconfigClientIdIsRecordedAsAFailure(): void
    {
        $report = $this->updater()->collect([new ServiceRef(101, 0, 'svc1')]);

        self::assertSame([], $report->usage);
        self::assertArrayHasKey(101, $report->failures);
        self::assertStringContainsString('client id', $report->failures[101]);
        self::assertSame([], $this->calledMethods()); // not even a login
    }

    public function testOneFailingServiceDoesNotAbortTheRest(): void
    {
        $first = $this->fake->addClient('svc1');
        $this->fake->addWebsite($first, 'one.example', hdQuotaMegabytes: 512);
        $second = $this->fake->addClient('svc2');
        $this->fake->addWebsite($second, 'two.example', hdQuotaMegabytes: 1024);

        $this->fake->failNextWith('quota_get_by_user', 'database_error: MySQL server has gone away');

        $report = $this->updater()->collect([
            new ServiceRef(101, $first, 'svc1'),
            new ServiceRef(102, $second, 'svc2'),
        ]);

        self::assertCount(1, $report->usage);
        self::assertSame(102, $report->usage[0]->serviceId);
        self::assertArrayHasKey(101, $report->failures);
        self::assertStringContainsString('gone away', $report->failures[101]);
    }

    public function testPermissionDeniedOnQuotaIsReportedPerServiceNotThrown(): void
    {
        $this->fake->setGrants(['trafficquota_get_by_user']);
        $clientId = $this->fake->addClient('svc1');

        $report = $this->updater()->collect([new ServiceRef(101, $clientId, 'svc1')]);

        self::assertSame([], $report->usage);
        self::assertStringContainsString('permissions', $report->failures[101]);
    }

    public function testLockoutAbortsTheWholeRunInsteadOfBurningEveryService(): void
    {
        // Prime the fake's per-IP failure counter with ten wrong-password
        // logins. Each uses a FRESH SessionManager - mirroring how every
        // per-operation entry point builds its own - because one manager now
        // latches the first LoginFailedException and fails fast on later calls
        // (spec 1.4: a bad login cannot become valid mid-batch), so ten
        // distinct managers are needed to spend ten real login attempts.
        for ($i = 0; $i < 10; $i++) {
            try {
                (new SessionManager(new RestClient($this->fake), 'remote', 'wrong-password'))
                    ->call('client_get', ['client_id' => 1]);
            } catch (\Throwable) {
                // priming the fake's per-IP failure counter
            }
        }

        $clientId = $this->fake->addClient('svc1');
        $updater = new Updater(new SessionManager(new RestClient($this->fake), 'remote', 'secret'));

        $this->expectException(LockoutException::class);
        $updater->collect([new ServiceRef(101, $clientId, 'svc1')]);
    }

    public function testADeletedPanelClientIsSkippedWithAReasonInsteadOfImportingZeroes(): void
    {
        // A stale ispconfig_client_id (client deleted in the panel by hand)
        // makes clientid_to_groups_list() return NULL, and getAuthSQL()'s
        // fallback clause then matches NOTHING (sys_perm_other = '' on
        // web_domain rows) - the quota calls answer [] with code ok, exactly
        // like a client that owns no website. Importing that as
        // diskusage=0/bwusage=0 forever would be silent wrong billing, so the
        // collector must tell the two apart (client_get: a miss is null) and
        // report an explicit skip the entry file can log.
        $first = $this->fake->addClient('svc1');
        $this->fake->addWebsite($first, 'one.example', hdQuotaMegabytes: 512);

        $report = $this->updater()->collect([new ServiceRef(101, 999, 'gone')]);

        self::assertSame([], $report->usage);
        self::assertArrayHasKey(101, $report->failures);
        self::assertStringContainsString('no longer exists', $report->failures[101]);
        self::assertContains('client_get', $this->calledMethods());
    }

    public function testTheExistenceCheckOnlyRunsWhenTheQuotaResponseIsEmpty(): void
    {
        // A service whose website exists proves the client exists (the rows
        // are scoped by the client's own group list), so the sweep must stay
        // at two calls per client and not pay a third for every service.
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: 512);

        $this->updater()->collect([new ServiceRef(101, $clientId, 'svc1')]);

        self::assertNotContains('client_get', $this->calledMethods());
    }

    public function testARecordSetSpanningSeveralGroupsIsRefusedRatherThanSummed(): void
    {
        // clientid_to_groups_list() concatenates a reseller's OWN group with
        // its sub-clients' groups (functions.inc.php:687, the parent_client_id
        // branch), so quota_get_by_user for a reseller id returns records
        // spanning several sys_groupids. This module never provisions
        // resellers - summing other clients' sites into one service's bill
        // would be a catastrophic overcharge, so the span is refused.
        $this->fake->quotaData = [
            ['domain_id' => '1', 'sys_groupid' => '3', 'active' => 'y', 'hd_quota' => '512', 'traffic_quota' => '512'],
            ['domain_id' => '2', 'sys_groupid' => '5', 'active' => 'y', 'hd_quota' => '1024', 'traffic_quota' => '1024'],
        ];
        $clientId = $this->fake->addClient('reseller1');

        $report = $this->updater()->collect([new ServiceRef(101, $clientId, 'reseller1')]);

        self::assertSame([], $report->usage);
        self::assertArrayHasKey(101, $report->failures);
        self::assertStringContainsString('sys_groupid', $report->failures[101]);
    }

    public function testASuspendedClientDoesNotTripTheTrafficCrossCheck(): void
    {
        // Suspension sets web_domain.active = 'n', so the traffic query
        // legitimately returns nothing while the disk quota still lists the
        // site - that must stay a normal zero-usage import.
        $clientId = $this->fake->addClient('svc1');
        $domainId = $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: 512, active: 'n');
        $this->fake->setDiskUsage('web' . $domainId, usedKilobytes: 262144);

        $report = $this->updater()->collect([new ServiceRef(101, $clientId, 'svc1')]);

        self::assertSame([], $report->failures);
        self::assertSame(256, $report->usage[0]->diskUsageMegabytes);
        self::assertSame(0, $report->usage[0]->bandwidthUsageMegabytes);
    }

    public function testEmptyServiceListDoesNoIo(): void
    {
        $report = $this->updater()->collect([]);

        self::assertSame([], $report->usage);
        self::assertSame([], $report->failures);
        self::assertTrue($report->isEmpty());
        self::assertSame([], $this->calledMethods());
    }
}
