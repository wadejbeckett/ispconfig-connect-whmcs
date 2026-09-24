<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use Ispconfigconnect\Tests\Stubs\ModuleCallLog;

/**
 * ispconfigconnect_UsageUpdate - WHMCS's nightly per-server disk/bandwidth
 * import (developers.whmcs.com/provisioning-modules/usage-update/): "The
 * UsageUpdate function performs a daily import of the disk and bandwidth usage
 * for accounts of a server", run by cron, per server rather than per product.
 *
 * The two optional arguments are test seams: WHMCS always calls the function
 * with $params alone, and the defaults then read tblhosting and write it back
 * through Capsule.
 */
final class UsageUpdateTest extends ModuleTestCase
{
    /** @var array<int, array<string, mixed>> serviceId => written columns */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->writes = [];
    }

    private function writer(): \Closure
    {
        return function (int $serviceId, array $columns): void {
            $this->writes[$serviceId] = $columns;
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return \Closure(int): list<array<string, mixed>>
     */
    private function rows(array $rows): \Closure
    {
        return static fn (int $serverId): array => $rows;
    }

    /** @return array<string, mixed> */
    private function row(int $serviceId, int $clientId, string $username): array
    {
        return [
            'id' => $serviceId,
            'username' => $username,
            'ispconfig_client_id' => (string) $clientId,
            'ispconfig_client_username' => $username,
        ];
    }

    public function testWritesTheFourTblhostingColumnsInMegabytes(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $domainId = $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: 1024, trafficQuotaMegabytes: 10240);
        $this->fake->setDiskUsage('web' . $domainId, usedKilobytes: 524288);
        $this->fake->setWebTraffic('one.example', thisMonthBytes: 2147483648);

        ispconfigconnect_UsageUpdate(
            $this->params(['serverid' => 3]),
            $this->rows([$this->row(101, $clientId, 'svc1')]),
            $this->writer()
        );

        self::assertSame(
            ['diskusage' => 512, 'disklimit' => 1024, 'bwusage' => 2048, 'bwlimit' => 10240],
            $this->writes[101]
        );
    }

    public function testMonthRolloverResetsStoredBandwidthInsteadOfKeepingLastMonthsPeak(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addWebsite($clientId, 'one.example', trafficQuotaMegabytes: 10240);
        // Measured this month: 1024 MiB.
        $this->fake->setWebTraffic('one.example', thisMonthBytes: 1073741824);
        // A 51200 MB peak stored LAST month must not carry into this month.
        $lastMonth = (new \DateTimeImmutable('first day of last month'))->format('Y-m-d H:i:s');
        $row = $this->row(101, $clientId, 'svc1') + ['bwusage' => 51200, 'lastupdate' => $lastMonth];

        ispconfigconnect_UsageUpdate($this->params(['serverid' => 3]), $this->rows([$row]), $this->writer());

        self::assertSame(1024, $this->writes[101]['bwusage']);
    }

    public function testThisMonthsAccumulatedBandwidthIsNotErasedByALowerReading(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addWebsite($clientId, 'one.example', trafficQuotaMegabytes: 10240);
        // A suspended site stops accruing, so the panel reports a LOWER 1024 MiB
        // than the 5120 MB WHMCS already holds for THIS month - keep the higher.
        $this->fake->setWebTraffic('one.example', thisMonthBytes: 1073741824);
        $thisMonth = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $row = $this->row(101, $clientId, 'svc1') + ['bwusage' => 5120, 'lastupdate' => $thisMonth];

        ispconfigconnect_UsageUpdate($this->params(['serverid' => 3]), $this->rows([$row]), $this->writer());

        self::assertSame(5120, $this->writes[101]['bwusage']);
    }

    public function testUsesOneLoginForTheWholeServerSweep(): void
    {
        $first = $this->fake->addClient('svc1');
        $this->fake->addWebsite($first, 'one.example');
        $second = $this->fake->addClient('svc2');
        $this->fake->addWebsite($second, 'two.example');

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(101, $first, 'svc1'), $this->row(102, $second, 'svc2')]),
            $this->writer()
        );

        self::assertSame(
            1,
            count(array_filter($this->calledMethods(), static fn (string $m): bool => $m === 'login'))
        );
        self::assertSame(0, $this->fake->activeSessionCount());
        self::assertSame([101, 102], array_keys($this->writes));
    }

    public function testOneUnreadableServiceDoesNotAbortTheBatch(): void
    {
        $first = $this->fake->addClient('svc1');
        $this->fake->addWebsite($first, 'one.example');
        $second = $this->fake->addClient('svc2');
        $this->fake->addWebsite($second, 'two.example');
        $this->fake->failNextWith('quota_get_by_user', 'database_error SELECT');

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(101, $first, 'svc1'), $this->row(102, $second, 'svc2')]),
            $this->writer()
        );

        self::assertSame([102], array_keys($this->writes));
    }

    public function testSkippedServicesAreNamedInTheModuleLog(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addWebsite($clientId, 'one.example');

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([
                $this->row(101, $clientId, 'svc1'),
                // Never provisioned by this module: no ispconfig_client_id.
                ['id' => 102, 'username' => 'legacy', 'ispconfig_client_id' => '', 'ispconfig_client_username' => ''],
            ]),
            $this->writer()
        );

        self::assertSame([101], array_keys($this->writes));

        $summary = self::lastUsageUpdateLog();
        self::assertSame(1, $summary['response']['updated']);
        self::assertArrayHasKey(102, $summary['response']['skipped']);
        self::assertStringContainsString('client id', (string) $summary['response']['skipped'][102]);
    }

    public function testADeletedPanelClientIsSkippedWithoutAbortingTheBatch(): void
    {
        // Service 101's ispconfig_client_id points at a client removed from
        // the panel by hand. The quota calls answer zero rows with code ok
        // (quota_lib's getAuthSQL scope collapses - see Collector), so the
        // stale id is only caught by the client_get existence check, and it
        // must cost service 101 alone - not the rest of the sweep.
        $alive = $this->fake->addClient('svc2');
        $this->fake->addWebsite($alive, 'two.example');

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(101, 9999, 'gone'), $this->row(102, $alive, 'svc2')]),
            $this->writer()
        );

        self::assertSame([102], array_keys($this->writes));
        $summary = self::lastUsageUpdateLog();
        self::assertSame(1, $summary['response']['updated']);
        self::assertStringContainsString('no longer exists', (string) $summary['response']['skipped'][101]);
        self::assertArrayNotHasKey('error', $summary['response']);
    }

    public function testUnlimitedQuotasReachTheWriterWithoutLimitColumns(): void
    {
        // hd_quota -1 / traffic_quota -1 (the module's own provisioning
        // default): ServiceUsage::toHostingColumns() omits disklimit/bwlimit
        // so the nightly sweep cannot zero an admin-configured WHMCS limit,
        // and the writer must receive exactly that limit-omitted array.
        $clientId = $this->fake->addClient('svc1');
        $domainId = $this->fake->addWebsite($clientId, 'one.example');
        $this->fake->setDiskUsage('web' . $domainId, usedKilobytes: 524288);
        $this->fake->setWebTraffic('one.example', thisMonthBytes: 2147483648);

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(101, $clientId, 'svc1')]),
            $this->writer()
        );

        self::assertSame(['diskusage' => 512, 'bwusage' => 2048], $this->writes[101]);
    }

    public function testAWriteFailureIsRecordedWithoutStoppingTheSweep(): void
    {
        $first = $this->fake->addClient('svc1');
        $this->fake->addWebsite($first, 'one.example');
        $second = $this->fake->addClient('svc2');
        $this->fake->addWebsite($second, 'two.example');

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(101, $first, 'svc1'), $this->row(102, $second, 'svc2')]),
            function (int $serviceId, array $columns): void {
                if ($serviceId === 101) {
                    throw new \RuntimeException('deadlock on tblhosting');
                }
                $this->writes[$serviceId] = $columns;
            }
        );

        self::assertSame([102], array_keys($this->writes));
        $summary = self::lastUsageUpdateLog();
        self::assertSame(1, $summary['response']['updated']);
        self::assertStringContainsString('deadlock', (string) $summary['response']['skipped'][101]);
    }

    public function testAnUnreachablePanelAbortsTheSweepOnceInsteadOfPerService(): void
    {
        $first = $this->fake->addClient('svc1');
        $second = $this->fake->addClient('svc2');
        $this->fake->apiEnabled = false;

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(101, $first, 'svc1'), $this->row(102, $second, 'svc2')]),
            $this->writer()
        );

        self::assertSame([], $this->writes);
        $summary = self::lastUsageUpdateLog();
        self::assertStringContainsString('Remote API is disabled', (string) $summary['response']['error']);
        // One reachability probe, not one failed login per service: every
        // failed login feeds ISPConfig's 10-per-5-minutes IP lockout counter.
        // (A disabled API never reaches the fake's request log at all - the
        // handler die()s before dispatch - so the evidence is the absence of
        // any quota traffic.)
        self::assertSame(
            [],
            array_filter($this->calledMethods(), static fn (string $m): bool => $m === 'quota_get_by_user')
        );
    }

    public function testTheReachabilityProbeRunsOnceForTheWholeSweep(): void
    {
        $first = $this->fake->addClient('svc1');
        $this->fake->addWebsite($first, 'one.example');
        $second = $this->fake->addClient('svc2');
        $this->fake->addWebsite($second, 'two.example');

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(101, $first, 'svc1'), $this->row(102, $second, 'svc2')]),
            $this->writer()
        );

        self::assertSame(
            1,
            count(array_filter($this->calledMethods(), static fn (string $m): bool => $m === 'monitor_jobqueue_count'))
        );
    }

    public function testAServerWithNoServicesMakesNoApiCallAtAll(): void
    {
        ispconfigconnect_UsageUpdate($this->params(), $this->rows([]), $this->writer());

        self::assertSame([], $this->calledMethods());
        self::assertSame([], $this->writes);
    }

    public function testALockoutStopsTheSweepInsteadOfExtendingIt(): void
    {
        $first = $this->fake->addClient('svc1');
        $this->fake->addWebsite($first, 'one.example');
        $second = $this->fake->addClient('svc2');
        $this->fake->addWebsite($second, 'two.example');
        $this->fake->failNextWith('quota_get_by_user', 'The login failure limit has been reached.');

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(101, $first, 'svc1'), $this->row(102, $second, 'svc2')]),
            $this->writer()
        );

        // Nothing is written and, crucially, no further quota call is made:
        // every retry would extend the panel's 5-minute IP lockout.
        self::assertSame([], $this->writes);
        self::assertSame(
            1,
            count(array_filter($this->calledMethods(), static fn (string $m): bool => $m === 'quota_get_by_user'))
        );
        $summary = self::lastUsageUpdateLog();
        self::assertStringContainsString('login failure limit', (string) $summary['response']['error']);
    }

    /** @return array{action: string, request: array<string, mixed>, response: array<string, mixed>} */
    private static function lastUsageUpdateLog(): array
    {
        $entries = array_values(array_filter(
            ModuleCallLog::entries(),
            static fn (array $entry): bool => $entry['action'] === 'UsageUpdate'
        ));
        self::assertNotSame([], $entries, 'UsageUpdate must record a summary in the module log');

        $last = $entries[count($entries) - 1];
        self::assertIsArray($last['request']);
        self::assertIsArray($last['response']);

        /** @var array{action: string, request: array<string, mixed>, response: array<string, mixed>} $last */
        return $last;
    }
}
