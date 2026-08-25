<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Usage;

use Ispconfigconnect\Tests\Stubs\ModuleCallLog;
use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Support\Logging;
use WHMCS\Module\Server\Ispconfigconnect\Sync\AccountLister;
use WHMCS\Module\Server\Ispconfigconnect\Usage\ServiceRef;
use WHMCS\Module\Server\Ispconfigconnect\Usage\Updater;

/**
 * Security regression: the nightly sweeps put PRIVATE KEY MATERIAL on the
 * wire, and every wire call is logged to tblmodulelog (readable by every WHMCS
 * admin). quota_get_by_user returns web_domain rows via SELECT * - including
 * ssl_key, the site's TLS private key, and ssl_request
 * (install/sql/ispconfig3.sql:2036/:2033, quota_lib.inc.php get_quota_data) -
 * and ListAccounts' client_get(-1) returns client rows including id_rsa, the
 * client's SSH private key (:260; written by functions.inc.php:518).
 *
 * The full pipeline runs here - FakeIspconfigServer (which seeds those
 * columns exactly because the real panel emits them) -> RestClient ->
 * Support\Logging -> logModuleCall - and every logged entry must cover the
 * key material with its replaceVars, the way WHMCS masks values out of the
 * stored request/response.
 */
#[CoversNothing]
final class UsageLogSecretsTest extends TestCase
{
    private FakeIspconfigServer $fake;

    protected function setUp(): void
    {
        ModuleCallLog::reset();
        $this->fake = new FakeIspconfigServer();
    }

    protected function tearDown(): void
    {
        ModuleCallLog::reset();
    }

    private function session(): SessionManager
    {
        return new SessionManager(
            new RestClient($this->fake, Logging::wire('ispconfigconnect')),
            'remote',
            'secret'
        );
    }

    /** True when some logged entry's raw response carries $secret. */
    private static function secretWasOnTheWire(string $secret): bool
    {
        foreach (ModuleCallLog::entries() as $entry) {
            if (str_contains(print_r($entry['response'], true), $secret)) {
                return true;
            }
        }

        return false;
    }

    public function testUsageUpdateSweepNeverLeaksTheWebsiteTlsPrivateKey(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $domainId = $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: 1024);
        $this->fake->setDiskUsage('web' . $domainId, usedKilobytes: 524288);
        $tlsKey = FakeIspconfigServer::websiteTlsPrivateKey($domainId);

        $report = (new Updater($this->session()))->collect([new ServiceRef(101, $clientId, 'svc1')]);

        self::assertSame([], $report->failures);
        self::assertNotSame([], ModuleCallLog::entries());
        // Guard against a vacuous pass: the quota response really carried the
        // key (the log stores request/response INTACT; masking is driven by
        // replaceVars, which entriesLeaking() honours).
        self::assertTrue(
            self::secretWasOnTheWire($tlsKey),
            'quota_get_by_user did not put ssl_key on the wire - the fake no longer emits it'
        );
        self::assertSame([], ModuleCallLog::entriesLeaking($tlsKey));
    }

    public function testListAccountsRunNeverLeaksTheClientSshPrivateKey(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addWebsite($clientId, 'one.example');
        $sshKey = FakeIspconfigServer::clientSshPrivateKey($clientId);

        $accounts = (new AccountLister($this->session(), '203.0.113.10'))->listAccounts();

        self::assertCount(1, $accounts);
        self::assertTrue(
            self::secretWasOnTheWire($sshKey),
            'client_get(-1) did not put id_rsa on the wire - the fake no longer emits it'
        );
        self::assertSame([], ModuleCallLog::entriesLeaking($sshKey));
    }

    public function testNoSeededKeyMaterialLeaksAcrossBothSweepsTogether(): void
    {
        // One combined run, like a WHMCS cron night: UsageUpdate first, then a
        // Sync Accounts. Every secret the fake seeds anywhere must stay
        // covered in every entry of the combined log - ssl_request (the CSR)
        // included.
        $clientId = $this->fake->addClient('svc1');
        $domainId = $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: 512);
        $otherClient = $this->fake->addClient('svc2');
        $otherDomain = $this->fake->addWebsite($otherClient, 'two.example');

        (new Updater($this->session()))->collect([
            new ServiceRef(101, $clientId, 'svc1'),
            new ServiceRef(102, $otherClient, 'svc2'),
        ]);
        (new AccountLister($this->session(), '203.0.113.10'))->listAccounts();

        $secrets = [
            FakeIspconfigServer::websiteTlsPrivateKey($domainId),
            FakeIspconfigServer::websiteTlsPrivateKey($otherDomain),
            FakeIspconfigServer::clientSshPrivateKey($clientId),
            FakeIspconfigServer::clientSshPrivateKey($otherClient),
            FakeIspconfigServer::websiteCertificateRequest($domainId),
        ];
        foreach ($secrets as $secret) {
            self::assertSame([], ModuleCallLog::entriesLeaking($secret), $secret);
        }
    }
}
