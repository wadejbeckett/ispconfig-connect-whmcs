<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Server;

use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Server\RemoteMetaData;

#[CoversClass(RemoteMetaData::class)]
final class RemoteMetaDataTest extends TestCase
{
    private const USERNAME = 'whmcsremote';
    private const PASSWORD = 'remote-api-pw';

    /** Every grant the metadata probes need (Permissions has the aliases). */
    private const PROBE_GRANTS = [
        'server_get',               // server_get_app_version + server_get_all
        'monitor_jobqueue_count',
        'client_get_all',
        'sites_web_domain_get',     // website count (primary_id -1 = all records)
    ];

    private FakeIspconfigServer $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeIspconfigServer(self::USERNAME, self::PASSWORD);
        $this->fake->setGrants(self::PROBE_GRANTS);
    }

    public function testGathersEveryProbeIntoOneFlatArray(): void
    {
        $this->fake->appVersion = '3.3.1p1';
        $this->fake->jobqueueCount = 7;
        $this->fake->addServer('web01.example.net');
        $this->fake->addServer('mail01.example.net');
        $this->fake->addClient('alpha');
        $this->fake->addClient('bravo');
        $this->fake->addClient('charlie');

        $meta = $this->gather();

        self::assertSame('3.3.1p1', $meta['ispconfig_version']);
        self::assertSame(7, $meta['pending_jobs']);
        self::assertSame(3, $meta['client_count']);
        self::assertSame(2, $meta['server_count']);
        self::assertSame(RemoteMetaData::STATUS_OK, $meta['ispconfig_status']);
        self::assertArrayNotHasKey('ispconfig_error', $meta);
    }

    /**
     * WHMCS renders the 'service_count' key as "Services: N" beneath the
     * account count in the servers page's Remote Usage Stats cell
     * (live-verified on WHMCS 9.0.6). Accounts are ISPConfig CLIENTS, services
     * are the WEBSITES they own - mirroring how the bundled modules show
     * customers vs their subscriptions.
     */
    public function testServiceCountReportsWebsitesNotClients(): void
    {
        $alpha = $this->fake->addClient('alpha');
        $bravo = $this->fake->addClient('bravo');
        $this->fake->addWebsite($alpha, 'alpha.example.com');
        $this->fake->addWebsite($alpha, 'alpha2.example.com');
        $this->fake->addWebsite($bravo, 'bravo.example.com');

        $meta = $this->gather();

        self::assertSame(2, $meta['client_count']);
        self::assertSame(3, $meta['website_count']);
        self::assertSame(3, $meta['service_count']);
    }

    /**
     * web_domain also holds alias domains and vhost subdomains
     * (web_vhost_domain.tform.php) - only type='vhost' rows are websites.
     */
    public function testServiceCountExcludesAliasAndSubdomainRecords(): void
    {
        $client = $this->fake->addClient('alpha');
        $this->fake->addWebsite($client, 'alpha.example.com');
        $this->fake->addWebsite($client, 'alias.example.com', -1, -1, 'alias');
        $this->fake->addWebsite($client, 'sub.example.com', -1, -1, 'subdomain');

        $meta = $this->gather();

        self::assertSame(1, $meta['service_count']);
    }

    /** client_get_all returns FALSE, not [], on an empty panel (source quirk). */
    public function testEmptyPanelCountsAsZeroClientsRatherThanFailing(): void
    {
        $meta = $this->gather();

        self::assertSame(0, $meta['client_count']);
        self::assertSame(0, $meta['website_count']);
        self::assertSame(0, $meta['service_count']);
        self::assertSame(RemoteMetaData::STATUS_OK, $meta['ispconfig_status']);
    }

    public function testTheResultIsFlatAndJsonSerialisable(): void
    {
        $this->fake->addServer('web01.example.net');
        $this->fake->addClient('alpha');

        $meta = $this->gather();

        foreach ($meta as $key => $value) {
            self::assertIsString($key);
            self::assertIsScalar($value, $key . ' must be scalar - WHMCS stores meta_data as JSON');
        }
        self::assertJson(json_encode($meta, JSON_THROW_ON_ERROR));
    }

    public function testCheckedAtComesFromTheInjectedClock(): void
    {
        $meta = $this->gather(clock: static fn (): int => 1_700_000_000);

        self::assertSame('2023-11-14 22:13:20 UTC', $meta['checked_at']);
    }

    // ---- graceful degradation --------------------------------------------

    public function testOneMissingGrantDegradesInsteadOfFailingTheWholeCall(): void
    {
        // Everything but client_get_all: the counter probe is permission
        // denied, the rest must still render the servers page.
        $this->fake->setGrants(['server_get', 'monitor_jobqueue_count']);
        $this->fake->appVersion = '3.2.11p1';
        $this->fake->addServer('web01.example.net');

        $meta = $this->gather();

        self::assertSame('3.2.11p1', $meta['ispconfig_version']);
        self::assertSame(1, $meta['server_count']);
        self::assertArrayNotHasKey('client_count', $meta);
        self::assertArrayNotHasKey('service_count', $meta);
        self::assertSame(RemoteMetaData::STATUS_DEGRADED, $meta['ispconfig_status']);
        self::assertStringContainsString('clients', (string) $meta['ispconfig_error']);
        self::assertStringContainsString('permissions', (string) $meta['ispconfig_error']);
    }

    public function testAGenericRemoteFaultOnOneProbeLeavesTheOthersIntact(): void
    {
        $this->fake->failNextWith('monitor_jobqueue_count', 'database_error: table is marked as crashed');
        $this->fake->addClient('alpha');

        $meta = $this->gather();

        self::assertArrayNotHasKey('pending_jobs', $meta);
        self::assertSame(1, $meta['client_count']);
        self::assertSame(RemoteMetaData::STATUS_DEGRADED, $meta['ispconfig_status']);
        self::assertStringContainsString('pending jobs', (string) $meta['ispconfig_error']);
    }

    public function testAnUnexpectedResponseShapeIsNotTreatedAsAConnectionFailure(): void
    {
        // A version probe answering with the wrong shape says nothing about
        // the connection - the remaining probes must still run.
        $this->fake->failNextWith('server_get_app_version', 'no_server_found');
        $this->fake->jobqueueCount = 4;
        $this->fake->addClient('alpha');

        $meta = $this->gather();

        self::assertArrayNotHasKey('ispconfig_version', $meta);
        self::assertSame(4, $meta['pending_jobs']);
        self::assertSame(1, $meta['client_count']);
        self::assertSame(RemoteMetaData::STATUS_DEGRADED, $meta['ispconfig_status']);
    }

    // ---- connection-level failures ---------------------------------------

    /**
     * A bad credential must cost ONE login, not one per probe: ISPConfig locks
     * an IP out after 10 failed logins in 5 minutes and every further attempt
     * extends the window (remoting.inc.php, spec 1.4). The servers page still
     * renders - with a diagnostic instead of numbers.
     */
    public function testAFailedLoginAbortsTheRemainingProbes(): void
    {
        $session = new SessionManager(
            new RestClient($this->fake),
            self::USERNAME,
            'wrong-password'
        );

        $meta = (new RemoteMetaData($session))->gather();

        self::assertSame(RemoteMetaData::STATUS_UNAVAILABLE, $meta['ispconfig_status']);
        self::assertStringContainsString('Username or password wrong', (string) $meta['ispconfig_error']);
        self::assertSame(
            1,
            count(array_filter($this->fake->requestLog, static fn (array $call): bool => $call['method'] === 'login')),
            'one failed login only - retrying per probe would feed the panel lockout counter'
        );
    }

    public function testAnUnreachableApiStillReturnsARenderableArray(): void
    {
        $this->fake->apiEnabled = false;

        $meta = $this->gather();

        self::assertSame(RemoteMetaData::STATUS_UNAVAILABLE, $meta['ispconfig_status']);
        self::assertStringContainsString('Remote API is disabled', (string) $meta['ispconfig_error']);
        self::assertArrayHasKey('checked_at', $meta);
        // No stale numbers may be invented when nothing could be read.
        self::assertArrayNotHasKey('service_count', $meta);
    }

    /**
     * ISPConfig's data_processing_error faults concatenate every field error
     * into one message, so a fault text can be arbitrarily long - and this
     * string is stored in tblservers_remote.meta_data and rendered on an admin
     * page.
     */
    public function testTheDiagnosticIsBoundedSoItCannotBloatTheStoredJson(): void
    {
        foreach (['server_get_app_version', 'monitor_jobqueue_count', 'client_get_all', 'server_get_all'] as $method) {
            $this->fake->failNextWith($method, str_repeat('x', 400));
        }

        $meta = $this->gather();

        $error = (string) $meta['ispconfig_error'];
        self::assertGreaterThan(400, strlen($error), 'all four probes must have reported');
        self::assertLessThanOrEqual(500, strlen($error));
        self::assertStringEndsWith('...', $error);
    }

    // ---- session ownership ------------------------------------------------

    public function testGatherLeavesTheSessionOpenForItsOwner(): void
    {
        $this->fake->addClient('alpha');
        $session = $this->session();

        (new RemoteMetaData($session))->gather();

        self::assertNotContains('logout', array_column($this->fake->requestLog, 'method'));

        $session->close();
        self::assertContains('logout', array_column($this->fake->requestLog, 'method'));
    }

    public function testEveryProbeSharesOneSession(): void
    {
        $this->fake->addClient('alpha');

        $this->gather();

        self::assertSame(
            1,
            count(array_filter($this->fake->requestLog, static fn (array $call): bool => $call['method'] === 'login'))
        );
    }

    // ---- helpers ----------------------------------------------------------

    /** @return array<string, scalar> */
    private function gather(?\Closure $clock = null): array
    {
        return (new RemoteMetaData($this->session(), $clock))->gather();
    }

    private function session(): SessionManager
    {
        return new SessionManager(new RestClient($this->fake), self::USERNAME, self::PASSWORD);
    }
}
