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

/**
 * Provider produces the PLAIN data the entry file feeds to WHMCS's
 * \WHMCS\UsageBilling\Metrics\Metric / Usage objects - lib code never touches
 * a WHMCS class. Everything below is a real round trip against the 3.3.1p1
 * behavioral double.
 */
#[CoversClass(Provider::class)]
#[CoversClass(MetricDefinition::class)]
final class ProviderTest extends TestCase
{
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

    /**
     * A client with one of everything, so every metric has a non-zero value
     * and a wrong unit conversion cannot hide behind a zero.
     *
     * @return array{clientId: int, username: string}
     */
    private function seedFullClient(): array
    {
        $clientId = $this->fake->addClient('svc1');
        $domainId = $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: 1024, trafficQuotaMegabytes: 10240);
        $this->fake->setDiskUsage('web' . $domainId, usedKilobytes: 524288);       // 512 MiB
        $this->fake->setWebTraffic('one.example', thisMonthBytes: 3221225472);     // 3 GiB
        $this->fake->addFtpUser($clientId, $domainId, 'svc1');
        $this->fake->addFtpUser($clientId, $domainId, 'svc1_deploy');
        $this->fake->addMailUser($clientId, 'info@one.example', quotaBytes: 1073741824, usedBytes: 268435456);
        $this->fake->addDatabase($clientId, 'c1_one', quotaMegabytes: 500);
        $this->fake->setDatabaseSize('c1_one', bytes: 104857600);                  // 100 MiB
        $this->fake->addDnsZone($clientId, 'one.example');

        return ['clientId' => $clientId, 'username' => 'svc1'];
    }

    public function testMetricDefinitionsAreStaticAndNeedNoServer(): void
    {
        // WHMCS invokes metrics() in PRODUCT contexts, where no service and no
        // server is in scope (developers.whmcs.com/provisioning-modules/usage-metrics/),
        // so it must never touch the wire.
        $definitions = Provider::metrics();

        self::assertSame([], $this->calledMethods());
        self::assertNotSame([], $definitions);

        $names = array_map(static fn (MetricDefinition $m): string => $m->systemName, $definitions);
        self::assertSame(
            ['diskusage', 'bandwidth', 'mailquota', 'databasequota', 'websites', 'mailboxes', 'databases', 'ftpusers', 'dnszones'],
            $names
        );
        self::assertSame($names, array_values(array_unique($names)));
    }

    public function testTrafficIsTheOnlyPeriodMetricAndEverythingElseIsASnapshot(): void
    {
        foreach (Provider::metrics() as $definition) {
            $expected = $definition->systemName === 'bandwidth'
                ? Provider::TYPE_PERIOD_MONTH
                : Provider::TYPE_SNAPSHOT;
            self::assertSame($expected, $definition->type, $definition->systemName);
        }
    }

    public function testUnitsAreLimitedToTheOnesWhmcsShips(): void
    {
        // \WHMCS\UsageBilling\Metrics\Units ships Bytes, MegaBytes, GigaBytes,
        // Accounts and Domains; the entry file maps these strings onto them.
        foreach (Provider::metrics() as $definition) {
            self::assertContains(
                $definition->unit,
                [Provider::UNIT_MEGABYTES, Provider::UNIT_ACCOUNTS, Provider::UNIT_DOMAINS],
                $definition->systemName
            );
            self::assertNotSame('', $definition->displayName, $definition->systemName);
            self::assertNotSame('', $definition->unitLabel, $definition->systemName);
        }
    }

    public function testTenantUsageReportsEveryMetricForTheClient(): void
    {
        $client = $this->seedFullClient();

        $values = $this->provider()->tenantUsage(
            new ServiceRef(101, $client['clientId'], $client['username'])
        );

        self::assertSame(
            [
                'diskusage' => 512,
                'bandwidth' => 3072,
                'mailquota' => 256,
                'databasequota' => 100,
                'websites' => 1,
                'mailboxes' => 1,
                'databases' => 1,
                'ftpusers' => 2,
                'dnszones' => 1,
            ],
            $values
        );
    }

    public function testTenantUsageKeysMatchTheMetricDefinitionsExactly(): void
    {
        $client = $this->seedFullClient();

        $values = $this->provider()->tenantUsage(
            new ServiceRef(101, $client['clientId'], $client['username'])
        );

        self::assertSame(
            array_map(static fn (MetricDefinition $m): string => $m->systemName, Provider::metrics()),
            array_keys($values)
        );
    }

    public function testTenantUsageUsesTheSixPerClientReadsAndNoMore(): void
    {
        $client = $this->seedFullClient();

        $this->provider()->tenantUsage(new ServiceRef(101, $client['clientId'], $client['username']));

        self::assertSame(
            [
                'login',
                'quota_get_by_user',
                'trafficquota_get_by_user',
                'mailquota_get_by_user',
                'databasequota_get_by_user',
                'sites_ftp_user_get',
                'dns_zone_get',
            ],
            $this->calledMethods()
        );
    }

    public function testUsageIsKeyedByTheServiceUsername(): void
    {
        $first = $this->seedFullClient();
        $second = $this->fake->addClient('svc2');
        $secondDomain = $this->fake->addWebsite($second, 'two.example', hdQuotaMegabytes: 2048);
        $this->fake->setDiskUsage('web' . $secondDomain, usedKilobytes: 1048576);

        $usage = $this->provider()->usage([
            new ServiceRef(101, $first['clientId'], $first['username']),
            new ServiceRef(102, $second, 'svc2'),
        ]);

        self::assertSame(['svc1', 'svc2'], array_keys($usage));
        self::assertSame(512, $usage['svc1']['diskusage']);
        self::assertSame(1024, $usage['svc2']['diskusage']);
        self::assertSame(0, $usage['svc2']['mailboxes']);
    }

    public function testDnsOnlyClientResolvesItsGroupThroughTheUsername(): void
    {
        // No website, mailbox or database means no record carries the client's
        // sys_groupid, so the group is resolved from sys_user.default_group via
        // client_get_by_username (remote.d/client.inc.php) - otherwise a
        // DNS-only product would always report zero zones.
        $clientId = $this->fake->addClient('dnsonly');
        $this->fake->addDnsZone($clientId, 'zone.example');
        $this->fake->addDnsZone($clientId, 'other.example');

        $values = $this->provider()->tenantUsage(new ServiceRef(101, $clientId, 'dnsonly'));

        self::assertSame(2, $values['dnszones']);
        self::assertSame(0, $values['websites']);
        self::assertContains('client_get_by_username', $this->calledMethods());
    }

    public function testWithoutAUsernameAnUnresolvableGroupYieldsZeroCountsAndNoExtraCalls(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addDnsZone($clientId, 'zone.example');

        $values = $this->provider()->tenantUsage(new ServiceRef(101, $clientId));

        self::assertSame(0, $values['dnszones']);
        self::assertSame(0, $values['ftpusers']);
        self::assertNotContains('sites_ftp_user_get', $this->calledMethods());
        self::assertNotContains('dns_zone_get', $this->calledMethods());
    }

    public function testFtpAndDnsListingsAreScopedToTheClientGroup(): void
    {
        $first = $this->seedFullClient();
        $second = $this->fake->addClient('svc2');
        $secondDomain = $this->fake->addWebsite($second, 'two.example');
        $this->fake->addFtpUser($second, $secondDomain, 'svc2');
        $this->fake->addDnsZone($second, 'two.example');

        $values = $this->provider()->tenantUsage(
            new ServiceRef(101, $first['clientId'], $first['username'])
        );

        self::assertSame(2, $values['ftpusers']);
        self::assertSame(1, $values['dnszones']);
    }

    public function testUsageSkipsServicesWithoutAUsernameBecauseWhmcsMatchesTenantsByIt(): void
    {
        $client = $this->seedFullClient();

        $provider = $this->provider();
        $usage = $provider->usage([
            new ServiceRef(101, $client['clientId'], $client['username']),
            new ServiceRef(102, 7),
        ]);

        self::assertSame(['svc1'], array_keys($usage));
        self::assertArrayHasKey(102, $provider->failures());
        self::assertStringContainsString('username', $provider->failures()[102]);
    }

    public function testTwoServicesSharingAUsernameAreBothFailedNotSilentlyCollapsed(): void
    {
        // WHMCS keys usage by the account username, so two services that share
        // one collide on the tenant key. Keying both under it would let the last
        // write silently overwrite the first, handing one service's usage to the
        // other (review U7). Both must be failed, and nothing reported for the
        // shared key. A shared username reaches usage() via the
        // tblhosting.username fallback or when two services link to one client.
        $first = $this->fake->addClient('clientA');
        $this->fake->addWebsite($first, 'a.example', hdQuotaMegabytes: 512);
        $second = $this->fake->addClient('clientB');
        $this->fake->addWebsite($second, 'b.example', hdQuotaMegabytes: 1024);

        $provider = $this->provider();
        $usage = $provider->usage([
            new ServiceRef(101, $first, 'dup'),
            new ServiceRef(102, $second, 'dup'),
        ]);

        self::assertSame([], $usage);
        self::assertArrayHasKey(101, $provider->failures());
        self::assertArrayHasKey(102, $provider->failures());
        self::assertStringContainsString('shared', $provider->failures()[101]);
        self::assertStringContainsString('102', $provider->failures()[101]);
        self::assertStringContainsString('101', $provider->failures()[102]);
        // Neither collided service is read from the panel at all.
        self::assertNotContains('quota_get_by_user', $this->calledMethods());
    }

    public function testAUniqueUsernameIsStillReportedAlongsideACollidingPair(): void
    {
        $unique = $this->seedFullClient();
        $a = $this->fake->addClient('clientA');
        $this->fake->addWebsite($a, 'a.example');
        $b = $this->fake->addClient('clientB');
        $this->fake->addWebsite($b, 'b.example');

        $provider = $this->provider();
        $usage = $provider->usage([
            new ServiceRef(101, $unique['clientId'], $unique['username']),
            new ServiceRef(102, $a, 'dup'),
            new ServiceRef(103, $b, 'dup'),
        ]);

        self::assertSame(['svc1'], array_keys($usage));
        self::assertArrayNotHasKey(101, $provider->failures());
        self::assertArrayHasKey(102, $provider->failures());
        self::assertArrayHasKey(103, $provider->failures());
    }

    public function testADeletedPanelClientIsAFailureNotAZeroUsageTenant(): void
    {
        // A stale ispconfig_client_id answers every quota call with zero rows
        // (getAuthSQL's fallback clause matches nothing for a client id
        // without a client row - see Collector::assertAttributable), which is
        // byte-identical to a client that owns nothing. Reporting that as
        // all-zero metrics would silently freeze the tenant's usage history,
        // so it must surface as a failure instead.
        $first = $this->seedFullClient();

        $provider = $this->provider();
        $usage = $provider->usage([
            new ServiceRef(101, $first['clientId'], $first['username']),
            new ServiceRef(102, 999, 'gone'),
        ]);

        self::assertSame(['svc1'], array_keys($usage));
        self::assertArrayHasKey(102, $provider->failures());
        self::assertStringContainsString('no longer exists', $provider->failures()[102]);
    }

    public function testOneFailingTenantDoesNotAbortTheSweep(): void
    {
        $first = $this->seedFullClient();
        $second = $this->fake->addClient('svc2');
        $this->fake->addWebsite($second, 'two.example');

        $this->fake->failNextWith('quota_get_by_user', 'database_error: MySQL server has gone away');

        $provider = $this->provider();
        $usage = $provider->usage([
            new ServiceRef(101, $first['clientId'], $first['username']),
            new ServiceRef(102, $second, 'svc2'),
        ]);

        self::assertSame(['svc2'], array_keys($usage));
        self::assertStringContainsString('gone away', $provider->failures()[101]);
    }

    public function testRepeatedTenantUsageForOneClientHitsTheApiOnce(): void
    {
        $client = $this->seedFullClient();
        $provider = $this->provider();
        $ref = new ServiceRef(101, $client['clientId'], $client['username']);

        $first = $provider->tenantUsage($ref);
        $callsAfterFirst = count($this->calledMethods());
        $second = $provider->tenantUsage($ref);

        self::assertSame($first, $second);
        self::assertCount($callsAfterFirst, $this->calledMethods());
    }
}
