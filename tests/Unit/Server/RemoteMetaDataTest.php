<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Server;

use Ispconfigconnect\Tests\Stubs\ModuleCallLog;
use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Server\MetaDataPanel;
use WHMCS\Module\Server\Ispconfigconnect\Server\RemoteMetaData;
use WHMCS\Module\Server\Ispconfigconnect\Server\ServerMetaData;
use WHMCS\Module\Server\Ispconfigconnect\Support\Logging;

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

    // ---- the servers-page key contract ------------------------------------
    //
    // meta_data is a shared surface: core reads 'service_count', the module's
    // admin hook reads the rest, and a renamed key does not fail anywhere - it
    // just silently drops a line off Configuration > Servers. These two tests
    // are what makes that impossible to do by accident.

    public function testTheEmittedKeySetIsExactlyWhatTheServersPageConsumes(): void
    {
        $this->fake->appVersion = '3.3.1p1';
        $this->fake->jobqueueCount = 2;
        $this->fake->addServer('web01.example.net');
        $this->fake->addWebsite($this->fake->addClient('alpha'), 'alpha.example.com');

        $keys = array_keys($this->gather());
        sort($keys);

        // 'sso_available' is deliberately absent: the entry file adds it after
        // gather() returns, because the probe is not an ISPConfig core call.
        self::assertSame([
            'checked_at',
            'client_count',
            'ispconfig_status',
            'ispconfig_version',
            'pending_jobs',
            'server_count',
            'service_count',
            'website_count',
        ], $keys);
    }

    public function testEveryProbeValueSurvivesTheRoundTripOntoTheServersPage(): void
    {
        $this->fake->appVersion = '3.3.1p1';
        $this->fake->jobqueueCount = 7;
        $this->fake->addServer('web01.example.net');
        $this->fake->addServer('mail01.example.net');
        foreach (['alpha', 'bravo', 'charlie'] as $name) {
            $this->fake->addClient($name);
        }

        $lines = self::panelLines($this->gather(clock: static fn (): int => 1_700_000_000));

        self::assertSame('3.3.1p1', $lines['ISPConfig Version'] ?? null);
        self::assertSame('7', $lines['Pending Jobs'] ?? null);
        self::assertSame('3', $lines['Clients'] ?? null);
        self::assertSame('2', $lines['ISPConfig Nodes'] ?? null);
        // checked_at stands in for WHMCS's own updated_at on a row it has not
        // written yet.
        self::assertSame('2023-11-14 22:13:20 UTC', $lines['Last Updated'] ?? null);
        self::assertArrayNotHasKey('Probe Errors', $lines);
    }

    public function testAFailedProbeReachesTheOperatorInsteadOfLeavingAGap(): void
    {
        $this->fake->setGrants(['server_get', 'monitor_jobqueue_count']);
        $this->fake->appVersion = '3.3.1p1';

        $lines = self::panelLines($this->gather());

        self::assertArrayNotHasKey('Clients', $lines);
        self::assertStringContainsString('clients', $lines['Probe Errors'] ?? '');
    }

    /**
     * Security regression, same class as tests/Unit/Usage/UsageLogSecretsTest.
     *
     * The website counter is a BULK read: sites_web_domain_get(-1) is a
     * `SELECT *` over web_domain, so every row comes back carrying ssl_key -
     * the site's TLS PRIVATE KEY - and ssl_request, its CSR
     * (install/sql/ispconfig3.sql:2036/:2033). Unlike the nightly sweeps this
     * probe runs on an ADMIN PAGE RENDER, so it repeats whenever an operator
     * opens Configuration > Servers, and tblmodulelog is readable by every
     * WHMCS admin.
     *
     * RestClient masks those columns (SENSITIVE_KEYS) precisely so bulk reads
     * are safe; this proves the metadata refresh is inside that protection.
     */
    public function testRefreshingTheMetadataNeverLeaksWebsiteKeyMaterial(): void
    {
        ModuleCallLog::reset();

        try {
            $client = $this->fake->addClient('alpha');
            $domainId = $this->fake->addWebsite($client, 'alpha.example.com');
            $tlsKey = FakeIspconfigServer::websiteTlsPrivateKey($domainId);
            $csr = FakeIspconfigServer::websiteCertificateRequest($domainId);

            $session = new SessionManager(
                new RestClient($this->fake, Logging::wire('ispconfigconnect')),
                self::USERNAME,
                self::PASSWORD
            );
            $meta = (new RemoteMetaData($session))->gather();

            self::assertSame(1, $meta['service_count']);
            // Not a vacuous pass: the panel really did put the key on the wire.
            self::assertTrue(
                self::secretWasOnTheWire($tlsKey),
                'sites_web_domain_get(-1) did not return ssl_key - the fake no longer emits it'
            );
            self::assertSame([], ModuleCallLog::entriesLeaking($tlsKey));
            self::assertSame([], ModuleCallLog::entriesLeaking($csr));
        } finally {
            ModuleCallLog::reset();
        }
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

    /** True when some logged entry's raw response really carried $secret. */
    private static function secretWasOnTheWire(string $secret): bool
    {
        foreach (ModuleCallLog::entries() as $entry) {
            if (str_contains(print_r($entry['response'], true), $secret)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pushes a gather() result through the path a real install uses - stored as
     * JSON in tblservers_remote, decoded by ServerMetaData, rendered by
     * MetaDataPanel - and returns the block's label => value lines.
     *
     * The block travels as a JSON payload the hook's script places in the DOM,
     * so reading it back is a decode rather than HTML scraping.
     *
     * @param array<string, scalar> $meta
     * @return array<string, string>
     */
    private static function panelLines(array $meta): array
    {
        $html = MetaDataPanel::render([ServerMetaData::fromRow([
            'id' => 3,
            'name' => 'ISPConfig',
            'hostname' => 'panel.example.net',
            'meta_data' => json_encode($meta, JSON_THROW_ON_ERROR),
            // A server WHMCS has never refreshed has no updated_at.
            'updated_at' => null,
        ])]);

        $found = preg_match(
            '#<script type="application/json" id="' . MetaDataPanel::PAYLOAD_ELEMENT_ID . '">(.*?)</script>#s',
            $html,
            $match
        );
        self::assertSame(1, $found, 'the hook payload must be present');

        /** @var list<array{lines: list<array{label: string, value: string}>}> $payload */
        $payload = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);

        $lines = [];
        foreach ($payload[0]['lines'] as $line) {
            $lines[$line['label']] = $line['value'];
        }

        return $lines;
    }

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
