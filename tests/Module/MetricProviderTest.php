<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use WHMCS\UsageBilling\Contracts\Metrics\MetricInterface;
use WHMCS\UsageBilling\Contracts\Metrics\ProviderInterface;
use WHMCS\UsageBilling\Metrics\Metric;
use WHMCS\UsageBilling\Metrics\Units\Accounts;
use WHMCS\UsageBilling\Metrics\Units\Domains;
use WHMCS\UsageBilling\Metrics\Units\MegaBytes;

/**
 * ispconfigconnect_MetricProvider - WHMCS usage-billing metrics
 * (developers.whmcs.com/provisioning-modules/usage-metrics/). WHMCS wants an
 * object implementing \WHMCS\UsageBilling\Contracts\Metrics\ProviderInterface,
 * so the entry file adapts Metrics\Provider (plain data, no WHMCS types) into
 * the Metric/Usage objects core expects.
 *
 * The WHMCS classes used here are the doubles from WhmcsClassDoubles.php - the
 * real ones are ionCube-encoded and cannot be loaded outside a WHMCS install.
 */
final class MetricProviderTest extends ModuleTestCase
{
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

    private function seedClient(string $username): int
    {
        $clientId = $this->fake->addClient($username);
        $domainId = $this->fake->addWebsite($clientId, $username . '.example', hdQuotaMegabytes: 2048);
        $this->fake->setDiskUsage('web' . $domainId, usedKilobytes: 1048576);
        $this->fake->setWebTraffic($username . '.example', thisMonthBytes: 1073741824);
        $this->fake->addMailUser($clientId, 'box@' . $username . '.example', quotaBytes: 0, usedBytes: 52428800);
        $this->fake->addDatabase($clientId, 'c' . $clientId . '_db');
        $this->fake->setDatabaseSize('c' . $clientId . '_db', 10485760);
        $this->fake->addFtpUser($clientId, $domainId, $username . 'ftp');
        $this->fake->addDnsZone($clientId, $username . '.example');

        return $clientId;
    }

    /**
     * @param list<Metric> $metrics
     * @return array<string, int|float|null>
     */
    private static function values(array $metrics): array
    {
        $values = [];
        foreach ($metrics as $metric) {
            $values[$metric->systemName()] = $metric->usage()?->value();
        }

        return $values;
    }

    public function testReturnsAWhmcsProviderInterfaceImplementation(): void
    {
        $provider = ispconfigconnect_MetricProvider($this->params());

        self::assertInstanceOf(ProviderInterface::class, $provider);
    }

    public function testMetricsAreDescribedWithWhmcsTypesAndUnitsWithoutAnyApiCall(): void
    {
        $metrics = ispconfigconnect_MetricProvider($this->params())->metrics();

        self::assertCount(9, $metrics);
        self::assertSame(
            [
                'diskusage', 'bandwidth', 'mailquota', 'databasequota',
                'websites', 'mailboxes', 'databases', 'ftpusers', 'dnszones',
            ],
            array_map(static fn (Metric $metric): string => $metric->systemName(), $metrics)
        );

        $byName = [];
        foreach ($metrics as $metric) {
            $byName[$metric->systemName()] = $metric;
        }

        self::assertSame(MetricInterface::TYPE_SNAPSHOT, $byName['diskusage']->type());
        // Traffic is summed per calendar month by ISPConfig and overwritten on
        // each collection - WHMCS's TYPE_PERIOD_MONTH contract exactly.
        self::assertSame(MetricInterface::TYPE_PERIOD_MONTH, $byName['bandwidth']->type());
        self::assertInstanceOf(MegaBytes::class, $byName['diskusage']->units());
        self::assertInstanceOf(Accounts::class, $byName['mailboxes']->units());
        self::assertInstanceOf(Domains::class, $byName['dnszones']->units());
        self::assertSame('MB', $byName['diskusage']->units()->label());

        // metrics() is invoked in PRODUCT contexts where no server is in
        // scope, so it must never touch the wire.
        self::assertSame([], $this->calledMethods());
    }

    public function testUsageIsKeyedByTheIspconfigUsernameWithPopulatedFigures(): void
    {
        $clientId = $this->seedClient('svc1');

        $usage = ispconfigconnect_MetricProvider(
            $this->params(),
            $this->rows([$this->row(101, $clientId, 'svc1')])
        )->usage();

        self::assertSame(['svc1'], array_keys($usage));
        $values = self::values($usage['svc1']);
        self::assertSame(1024, $values['diskusage']);
        self::assertSame(1024, $values['bandwidth']);
        self::assertSame(50, $values['mailquota']);
        self::assertSame(10, $values['databasequota']);
        self::assertSame(1, $values['websites']);
        self::assertSame(1, $values['mailboxes']);
        self::assertSame(1, $values['databases']);
        self::assertSame(1, $values['ftpusers']);
        self::assertSame(1, $values['dnszones']);

        self::assertSame(0, $this->fake->activeSessionCount());
    }

    public function testUsageUsesOneLoginForEveryServiceOnTheServer(): void
    {
        $first = $this->seedClient('svc1');
        $second = $this->seedClient('svc2');

        $usage = ispconfigconnect_MetricProvider(
            $this->params(),
            $this->rows([$this->row(101, $first, 'svc1'), $this->row(102, $second, 'svc2')])
        )->usage();

        self::assertSame(['svc1', 'svc2'], array_keys($usage));
        self::assertSame(
            1,
            count(array_filter($this->calledMethods(), static fn (string $m): bool => $m === 'login'))
        );
    }

    public function testOneUnreadableServiceDoesNotCostTheOthersTheirUsage(): void
    {
        $first = $this->seedClient('svc1');
        $second = $this->seedClient('svc2');
        $this->fake->failNextWith('quota_get_by_user', 'database_error SELECT');

        $usage = ispconfigconnect_MetricProvider(
            $this->params(),
            $this->rows([$this->row(101, $first, 'svc1'), $this->row(102, $second, 'svc2')])
        )->usage();

        self::assertSame(['svc2'], array_keys($usage));
    }

    public function testAnUnreachablePanelYieldsNoUsageInsteadOfThrowing(): void
    {
        $clientId = $this->seedClient('svc1');
        $this->fake->apiEnabled = false;

        $usage = ispconfigconnect_MetricProvider(
            $this->params(),
            $this->rows([$this->row(101, $clientId, 'svc1')])
        )->usage();

        self::assertSame([], $usage);
    }

    public function testTenantUsageResolvesAUsernameString(): void
    {
        $clientId = $this->seedClient('svc1');

        $metrics = ispconfigconnect_MetricProvider(
            $this->params(),
            $this->rows([$this->row(101, $clientId, 'svc1')])
        )->tenantUsage('svc1');

        self::assertSame(1024, self::values($metrics)['diskusage']);
    }

    public function testTenantUsageResolvesAServiceIdAndAServiceModel(): void
    {
        $clientId = $this->seedClient('svc1');
        $provider = ispconfigconnect_MetricProvider(
            $this->params(),
            $this->rows([$this->row(101, $clientId, 'svc1')])
        );

        self::assertSame(1024, self::values($provider->tenantUsage(101))['diskusage']);

        $model = new \stdClass();
        $model->id = 101;
        $model->username = 'svc1';
        self::assertSame(1024, self::values($provider->tenantUsage($model))['diskusage']);
    }

    public function testAnUnknownTenantYieldsTheMetricCatalogueWithoutUsage(): void
    {
        $metrics = ispconfigconnect_MetricProvider($this->params(), $this->rows([]))->tenantUsage('nobody');

        self::assertCount(9, $metrics);
        self::assertSame([], array_filter(self::values($metrics), static fn (mixed $v): bool => $v !== null));
        self::assertSame([], $this->calledMethods());
    }

    public function testZeroUsageIsReportedRatherThanOmitted(): void
    {
        // A brand-new client with nothing provisioned yet: every figure is 0,
        // and 0 is a real reading - dropping it would leave WHMCS showing the
        // previous period's number forever.
        $clientId = $this->fake->addClient('svc1');

        $metrics = ispconfigconnect_MetricProvider(
            $this->params(),
            $this->rows([$this->row(101, $clientId, 'svc1')])
        )->tenantUsage('svc1');

        self::assertSame(0, self::values($metrics)['diskusage']);
        self::assertSame(0, self::values($metrics)['websites']);
    }
}
