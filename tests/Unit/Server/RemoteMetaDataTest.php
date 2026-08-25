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

    /**
     * Every grant the metadata probes need (Permissions has the aliases).
     *
     * `sites_web_domain_get` is deliberately NOT here: the probes must not read
     * website RECORDS, which is what makes the whole grant unnecessary for this
     * page. See testTheRefreshNeverAsksThePanelForRecordsThatCarryKeyMaterial.
     */
    private const PROBE_GRANTS = [
        'server_get',               // server_get_app_version + server_get_all
        'monitor_jobqueue_count',
        'client_get_all',
    ];

    /**
     * The only remote functions a servers-page refresh may call. Each answers
     * with a COUNT or a list of IDs; none returns a record, so none can carry
     * key material (see the leak test below).
     */
    private const ALLOWED_WIRE_METHODS = [
        'login',
        'logout',
        'server_get_app_version',
        'monitor_jobqueue_count',
        'client_get_all',
        'server_get_all',
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
     * (live-verified on WHMCS 9.0.6).
     *
     * It reports the ISPConfig CLIENT count, because this module provisions
     * exactly one client per WHMCS service (Provision\Recipe::create): the
     * client population IS the service population. The websites those clients
     * own are deliberately NOT counted - see
     * testTheRefreshNeverAsksThePanelForRecordsThatCarryKeyMaterial.
     */
    public function testServiceCountIsTheClientCountBecauseOneServiceIsOneClient(): void
    {
        $alpha = $this->fake->addClient('alpha');
        $bravo = $this->fake->addClient('bravo');
        // Three websites across two clients: the vhost total must not leak
        // into either number.
        $this->fake->addWebsite($alpha, 'alpha.example.com');
        $this->fake->addWebsite($alpha, 'alpha2.example.com');
        $this->fake->addWebsite($bravo, 'bravo.example.com');

        $meta = $this->gather();

        self::assertSame(2, $meta['client_count']);
        self::assertSame(2, $meta['service_count']);
        self::assertArrayNotHasKey('website_count', $meta);
    }

    /** client_get_all returns FALSE, not [], on an empty panel (source quirk). */
    public function testEmptyPanelCountsAsZeroClientsRatherThanFailing(): void
    {
        $meta = $this->gather();

        self::assertSame(0, $meta['client_count']);
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
        // 'website_count' is gone for good - counting vhosts cost a bulk read
        // of web_domain, key material included.
        self::assertSame([
            'checked_at',
            'client_count',
            'ispconfig_status',
            'ispconfig_version',
            'pending_jobs',
            'server_count',
            'service_count',
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
     * Security regression, same class as tests/Unit/Usage/UsageLogSecretsTest -
     * but one step stronger: the secrets must never be REQUESTED, not merely
     * masked before logging.
     *
     * This probe runs on an ADMIN PAGE RENDER, so it repeats whenever an
     * operator opens Configuration > Servers. The counting functions ISPConfig
     * offers over the remote API are bulk record reads, and
     * remoting_lib.inc.php getDataRecord() answers an array/-1 primary_id with
     * `SELECT *`:
     *
     *   - sites_web_domain_get(-1) returns every vhost row INCLUDING
     *     web_domain.ssl_key, the site's TLS PRIVATE KEY, and ssl_request, its
     *     CSR (install/sql/ispconfig3.sql);
     *   - client_get(-1) returns every client row INCLUDING client.id_rsa, the
     *     client's SSH PRIVATE key (functions.inc.php generate_ssh_key()).
     *
     * Masking would keep both out of tblmodulelog; nothing keeps them out of
     * the HTTP response, WHMCS's memory or any proxy in between. So the
     * contract enforced here is the wire itself: every call this class makes
     * answers with a count or a list of ids.
     */
    public function testTheRefreshNeverAsksThePanelForRecordsThatCarryKeyMaterial(): void
    {
        ModuleCallLog::reset();

        try {
            // Grant everything: nothing but the code's own restraint decides
            // which functions are called.
            $this->fake->grantAll();
            $client = $this->fake->addClient('alpha');
            $domainId = $this->fake->addWebsite($client, 'alpha.example.com');
            $tlsKey = FakeIspconfigServer::websiteTlsPrivateKey($domainId);
            $csr = FakeIspconfigServer::websiteCertificateRequest($domainId);
            $sshKey = FakeIspconfigServer::clientSshPrivateKey($client);

            // Not a vacuous pass: the seeded panel really does hold this key
            // material, and would hand it over to a record read.
            self::assertSame($tlsKey, $this->fake->sites()[$domainId]['ssl_key']);
            self::assertSame($sshKey, $this->fake->clients()[$client]['id_rsa']);

            $session = new SessionManager(
                new RestClient($this->fake, Logging::wire('ispconfigconnect')),
                self::USERNAME,
                self::PASSWORD
            );
            $meta = (new RemoteMetaData($session))->gather();
            $session->close();

            self::assertSame(1, $meta['service_count']);
            self::assertSame(
                [],
                array_diff(array_column($this->fake->requestLog, 'method'), self::ALLOWED_WIRE_METHODS),
                'a servers-page refresh called a record-reading function'
            );
            self::assertFalse(self::secretWasOnTheWire($tlsKey), 'a TLS private key crossed the wire');
            self::assertFalse(self::secretWasOnTheWire($csr), 'a CSR crossed the wire');
            self::assertFalse(self::secretWasOnTheWire($sshKey), 'an SSH private key crossed the wire');
            self::assertSame([], ModuleCallLog::entriesLeaking($tlsKey));
            self::assertSame([], ModuleCallLog::entriesLeaking($csr));
            self::assertSame([], ModuleCallLog::entriesLeaking($sshKey));
        } finally {
            ModuleCallLog::reset();
        }
    }

    /**
     * The cost side of the same decision: a panel with hundreds of websites
     * must not make the servers page any more expensive than a panel with
     * none. Five calls, whatever the panel holds.
     */
    public function testTheRefreshCostIsFixedNoMatterHowLargeThePanelIs(): void
    {
        $this->fake->addServer('web01.example.net');
        for ($i = 1; $i <= 50; $i++) {
            $this->fake->addWebsite($this->fake->addClient('client' . $i), 'site' . $i . '.example.com');
        }

        $meta = $this->gather();

        self::assertSame(50, $meta['service_count']);
        self::assertSame(
            ['login', 'server_get_app_version', 'monitor_jobqueue_count', 'client_get_all', 'server_get_all'],
            array_column($this->fake->requestLog, 'method')
        );
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
