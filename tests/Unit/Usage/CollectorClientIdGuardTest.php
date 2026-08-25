<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Usage;

use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Metrics\Provider;
use WHMCS\Module\Server\Ispconfigconnect\Usage\Collector;
use WHMCS\Module\Server\Ispconfigconnect\Usage\ServiceRef;
use WHMCS\Module\Server\Ispconfigconnect\Usage\UsageException;

/**
 * A FALSY ISPConfig client id is the module's worst over-billing input, and it
 * is reachable: ispconfigconnect_serviceRefs() deliberately keeps services
 * with no stored ispconfig_client_id so the operator can see them reported as
 * skipped, and MetricProvider::tenantUsage() resolves such a ServiceRef by
 * service id or username without ever looking at its client id.
 *
 * What ISPConfig does with client_id = 0 is not symmetric:
 *
 *   quota_get_by_user(0)        -> clientid_to_groups_list(0) returns NULL
 *                                  (functions.inc.php:685 "if ($client_id !=
 *                                  null)"), getAuthSQL falls back to
 *                                  "sys_perm_other like '%r%'"
 *                                  (tform_base.inc.php:1653), web_domain
 *                                  carries sys_perm_other NULL -> ZERO rows.
 *   trafficquota_get_by_user(0) -> the "$clientid != null" gate at
 *                                  quota_lib.inc.php:80 fails, $sql_where is
 *                                  never assigned, and the query degrades to
 *                                  every active vhost/vhostsubdomain/
 *                                  vhostalias ON THE WHOLE PANEL, each with
 *                                  its own SUM(traffic_bytes).
 *
 * So the failure mode is: zero websites, zero mailboxes, zero databases - and
 * every other customer's bandwidth attributed to one service. The guard has to
 * live below the callers, in Collector, because that is where the wire call is
 * made and it is the one place every consumer goes through.
 */
#[CoversClass(Collector::class)]
final class CollectorClientIdGuardTest extends TestCase
{
    private FakeIspconfigServer $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeIspconfigServer();
    }

    private function session(): SessionManager
    {
        return new SessionManager(new RestClient($this->fake), 'remote', 'secret');
    }

    /** @return list<string> */
    private function calledMethods(): array
    {
        return array_map(
            static fn (array $entry): string => (string) $entry['method'],
            $this->fake->requestLog
        );
    }

    /** Two unrelated customers, so a panel-wide read is unmistakable. */
    private function seedTwoUnrelatedCustomers(): void
    {
        $first = $this->fake->addClient('svc1');
        $this->fake->addWebsite($first, 'one.example');
        $this->fake->setWebTraffic('one.example', thisMonthBytes: 3221225472);   // 3 GiB

        $second = $this->fake->addClient('svc2');
        $this->fake->addWebsite($second, 'two.example');
        $this->fake->setWebTraffic('two.example', thisMonthBytes: 6442450944);   // 6 GiB
    }

    /**
     * The ground-truth pin: this is what the panel really answers, and it is
     * why the guard exists. If ISPConfig ever fixes quota_lib.inc.php:80 this
     * test is the thing that tells us the double has drifted.
     */
    public function testPanelAnswersAFalsyClientIdWithEveryDomainOnTheServer(): void
    {
        $this->seedTwoUnrelatedCustomers();

        $traffic = $this->session()->call('trafficquota_get_by_user', [
            'client_id' => 0,
            'lastdays' => 0,
        ]);

        self::assertIsArray($traffic);
        self::assertSame(['one.example', 'two.example'], array_keys($traffic));

        // ... while the disk call for the same id answers with nothing at all.
        $rows = $this->session()->call('quota_get_by_user', ['client_id' => 0]);
        self::assertSame([], $rows);
    }

    /**
     * A non-numeric client id lands in the same place: sites.inc.php:993
     * clears the first "!= null" gate, intval() turns it into 0, and
     * quota_lib.inc.php:80 then fails the second.
     */
    public function testANonNumericClientIdDegradesToTheSamePanelWideRead(): void
    {
        $this->seedTwoUnrelatedCustomers();

        $traffic = $this->session()->call('trafficquota_get_by_user', [
            'client_id' => 'not-a-number',
            'lastdays' => 0,
        ]);

        self::assertIsArray($traffic);
        self::assertCount(2, $traffic);
    }

    /** A negative id is scoped normally - the subselect just matches nothing. */
    public function testANegativeClientIdIsScopedAndSimplyMatchesNothing(): void
    {
        $this->seedTwoUnrelatedCustomers();

        $traffic = $this->session()->call('trafficquota_get_by_user', [
            'client_id' => -1,
            'lastdays' => 0,
        ]);

        self::assertSame([], $traffic);
    }

    /** @return list<array{int}> */
    public static function nonPositiveClientIds(): array
    {
        return [[0], [-1], [PHP_INT_MIN]];
    }

    #[DataProvider('nonPositiveClientIds')]
    public function testUsageForRefusesANonPositiveClientIdBeforeTouchingTheWire(int $clientId): void
    {
        $this->seedTwoUnrelatedCustomers();
        $collector = new Collector($this->session());

        try {
            $collector->usageFor($clientId);
            self::fail('a non-positive client id must not reach the panel');
        } catch (UsageException $refused) {
            self::assertStringContainsString('client id', $refused->getMessage());
        }

        self::assertNotContains('trafficquota_get_by_user', $this->calledMethods());
        self::assertNotContains('quota_get_by_user', $this->calledMethods());
    }

    #[DataProvider('nonPositiveClientIds')]
    public function testMetricsForRefusesANonPositiveClientIdBeforeTouchingTheWire(int $clientId): void
    {
        $this->seedTwoUnrelatedCustomers();
        $collector = new Collector($this->session());

        $this->expectException(UsageException::class);

        try {
            $collector->metricsFor($clientId, 'svc1');
        } finally {
            self::assertNotContains('trafficquota_get_by_user', $this->calledMethods());
        }
    }

    /**
     * The reachable end-to-end path. Without the guard this returns svc2's
     * 6 GiB (plus svc1's 3 GiB) as ONE service's bandwidth while reporting
     * zero websites - a nine-gigabyte invoice line for a service the module
     * never provisioned.
     */
    public function testTenantUsageForAnUnlinkedServiceFailsInsteadOfBillingThePanel(): void
    {
        $this->seedTwoUnrelatedCustomers();
        $provider = new Provider($this->session());

        $this->expectException(UsageException::class);
        $provider->tenantUsage(new ServiceRef(101, 0, 'svc1'));
    }

    /** The sweep already skipped these; it must keep doing so, with a reason. */
    public function testTheSweepStillRecordsAnUnlinkedServiceAsSkipped(): void
    {
        $this->seedTwoUnrelatedCustomers();
        $provider = new Provider($this->session());

        $usage = $provider->usage([new ServiceRef(101, 0, 'svc1')]);

        self::assertSame([], $usage);
        self::assertArrayHasKey(101, $provider->failures());
        self::assertNotContains('trafficquota_get_by_user', $this->calledMethods());
    }
}
