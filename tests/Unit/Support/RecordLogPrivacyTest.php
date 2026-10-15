<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Support;

use Ispconfigconnect\Tests\Stubs\ModuleCallLog;
use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\HttpTransportInterface;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\ServerConfig;
use WHMCS\Module\Server\Ispconfigconnect\Support\Factory;
use WHMCS\Module\Server\Ispconfigconnect\Sync\AccountLister;

/**
 * Security regression for the two remote reads that answer with a CUSTOMER
 * RECORD, both of which Server Sync makes on every run:
 *
 *   - `client_get` with client_id -1 is `SELECT * FROM client`
 *     (remoting_lib.inc.php getDataRecord(): "$primary_id == -1"), so one call
 *     returns EVERY client on the panel with `bank_account_owner`,
 *     `bank_account_number`, `bank_code`, `bank_name`, `bank_account_iban`,
 *     `bank_account_swift`, `paypal_email`, `vat_id`, `activation_code` and
 *     the whole postal contact record (install/sql/ispconfig3.sql:146-269);
 *   - `client_get_by_username` is a bare `SELECT * FROM sys_user`
 *     (remote.d/client.inc.php:478), so once per client it returns `passwort`
 *     (the panel login hash), `otp_data` (the 2FA mail address),
 *     `otp_recovery` (the bcrypt hash of the recovery codes),
 *     `lost_password_hash` and `id_rsa` (:1853-1882).
 *
 * Every one of those calls is written to tblmodulelog, which every WHMCS admin
 * can read - and on a real panel this is the banking record of hosting
 * customers who may not be WHMCS customers at all.
 *
 * The whole PRODUCTION wiring runs here: Factory::sessionManager() (with only
 * the transport substituted, via the documented test seam) -> RestClient ->
 * Factory's defensive pass -> Support\Logging -> logModuleCall.
 */
#[CoversNothing]
final class RecordLogPrivacyTest extends TestCase
{
    private const REMOTE_PASSWORD = 'remote-api-pw';

    private FakeIspconfigServer $fake;

    protected function setUp(): void
    {
        ModuleCallLog::reset();
        $this->fake = new FakeIspconfigServer('whmcsremote', self::REMOTE_PASSWORD);
        Factory::$transportFactory =
            fn (ServerConfig $config): HttpTransportInterface => $this->fake;
    }

    protected function tearDown(): void
    {
        Factory::$transportFactory = null;
        ModuleCallLog::reset();
    }

    /** @return array<string, mixed> the server half of a WHMCS provisioning $params */
    private function params(): array
    {
        return [
            'serverhostname' => 'panel.example.net',
            'serverip' => '203.0.113.10',
            'serverport' => '8080',
            'serversecure' => 'on',
            'serverusername' => 'whmcsremote',
            'serverpassword' => self::REMOTE_PASSWORD,
            'serveraccesshash' => '',
        ];
    }

    /**
     * One full Server Sync over a two-client panel, through the production
     * stack.
     *
     * @return list<int> the seeded ISPConfig client ids
     */
    private function runServerSync(): array
    {
        $first = $this->fake->addClient('panelcust1');
        $this->fake->addWebsite($first, 'one.example');
        $second = $this->fake->addClient('panelcust2');
        $this->fake->addWebsite($second, 'two.example');

        $session = Factory::sessionManager($this->params());
        try {
            $accounts = (new AccountLister($session, '203.0.113.10'))->listAccounts();
        } finally {
            $session->close();
        }

        self::assertCount(2, $accounts, 'both panel clients must be listed');
        self::assertNotSame([], ModuleCallLog::entries(), 'the sync must still be logged');

        return [$first, $second];
    }

    /**
     * Every array key that appears anywhere in the logged request or response
     * of any entry, at any nesting depth.
     *
     * @return array<string, true>
     */
    private static function loggedColumnNames(): array
    {
        $names = [];
        $walk = static function (mixed $node) use (&$walk, &$names): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    $names[$key] = true;
                }
                $walk($value);
            }
        };
        foreach (ModuleCallLog::entries() as $entry) {
            $walk($entry['request']);
            $walk($entry['response']);
        }

        return $names;
    }

    // ---- client row: money and identity ----------------------------------

    public function testServerSyncNeverWritesPanelBankingDetailsToTheModuleLog(): void
    {
        $clientIds = $this->runServerSync();
        $loggedColumns = self::loggedColumnNames();

        foreach ($clientIds as $clientId) {
            foreach (FakeIspconfigServer::clientCustomerRecord($clientId) as $column => $value) {
                // Structural: the column is not in the logged record at all.
                // This is the assertion that also covers the SHORT columns
                // (`gender` = 'f', `country` = 'DE') - values no leak detector
                // can search for without matching half the log by accident,
                // which is exactly why they are dropped rather than masked.
                self::assertArrayNotHasKey(
                    $column,
                    $loggedColumns,
                    sprintf('client.%s survived into tblmodulelog', $column)
                );

                // By value, for the columns whose seeded value is distinctive
                // enough to search for.
                if (strlen((string) $value) >= 6) {
                    self::assertSame(
                        [],
                        ModuleCallLog::entriesLeakingAfterMasking((string) $value),
                        sprintf('client.%s of ISPConfig client #%d reached tblmodulelog', $column, $clientId)
                    );
                }
            }
        }
    }

    // ---- sys_user row: credentials and 2FA -------------------------------

    public function testServerSyncNeverWritesTheSysUserCredentialColumnsToTheModuleLog(): void
    {
        $clientIds = $this->runServerSync();
        $loggedColumns = self::loggedColumnNames();

        foreach ($clientIds as $clientId) {
            $private = FakeIspconfigServer::sysUserPrivateRecord($clientId, 'crypt:seeded');
            foreach (['passwort', 'otp_data', 'otp_recovery', 'lost_password_hash'] as $column) {
                self::assertSame(
                    [],
                    ModuleCallLog::entriesLeakingAfterMasking((string) $private[$column]),
                    sprintf('sys_user.%s of ISPConfig client #%d reached tblmodulelog', $column, $clientId)
                );
            }

            // The 2FA mail address and the reset-token hash are personal data
            // the module never reads: they must be GONE, not merely masked.
            foreach (['otp_data', 'lost_password_hash', 'lost_password_reqtime'] as $column) {
                self::assertArrayNotHasKey(
                    $column,
                    $loggedColumns,
                    sprintf('sys_user.%s survived into tblmodulelog', $column)
                );
            }
        }
    }

    // ---- the guard against a vacuous pass --------------------------------

    public function testThePanelReallyPutsThoseColumnsOnTheWire(): void
    {
        [$clientId] = $this->runServerSync();

        // Read the same two records again WITHOUT a logger attached: this is
        // the unscrubbed panel answer, and it must still carry every column -
        // otherwise the assertions above pass because the fixture stopped
        // emitting them, not because the module stopped logging them.
        $raw = new RestClient($this->fake);
        $sessionId = $raw->login('whmcsremote', self::REMOTE_PASSWORD);

        $clients = $raw->call('client_get', ['session_id' => $sessionId, 'client_id' => -1]);
        self::assertIsArray($clients);
        self::assertSame(
            FakeIspconfigServer::clientCustomerRecord($clientId)['bank_account_iban'],
            $clients[0]['bank_account_iban'] ?? null
        );

        $sysUser = $raw->call('client_get_by_username', [
            'session_id' => $sessionId,
            'username' => 'panelcust1',
        ]);
        self::assertIsArray($sysUser);
        self::assertSame('crypt:seeded', $sysUser['passwort'] ?? null);
        self::assertSame(
            FakeIspconfigServer::sysUserPrivateRecord($clientId, '')['otp_recovery'],
            $sysUser['otp_recovery'] ?? null
        );
    }

    /**
     * The module's own callers must keep seeing the record the panel sent -
     * scrubbing is for the LOG copy only. AccountLister reads client.email off
     * the very record whose neighbouring columns are scrubbed.
     */
    public function testScrubbingDoesNotAlterWhatTheModuleItselfReceives(): void
    {
        $this->runServerSync();

        $session = Factory::sessionManager($this->params());
        try {
            $accounts = (new AccountLister($session, '203.0.113.10'))->listAccounts();
        } finally {
            $session->close();
        }

        self::assertSame('panelcust1@example.test', $accounts[0]->email);
        self::assertSame('panelcust1', $accounts[0]->username);
        self::assertSame('one.example', $accounts[0]->domain);
    }

    /**
     * The trap that produced the original bug, from the other side: a
     * substring rule for "password" also matches `lost_password_function`, a
     * 1/0 FLAG. Handing "1" to logModuleCall's replaceVars would blank every
     * "1" in the entry - ids, counts, quotas - so a masking list must never
     * pick up a value like that.
     */
    public function testAFlagColumnNamedLikeAPasswordIsNeverUsedAsAMaskingNeedle(): void
    {
        $this->runServerSync();

        foreach (ModuleCallLog::entries() as $index => $entry) {
            foreach ($entry['replaceVars'] as $value) {
                self::assertGreaterThan(
                    3,
                    strlen($value),
                    sprintf(
                        'entry #%d (%s) masks the short value "%s" - str_replace would corrupt the log',
                        $index,
                        $entry['action'],
                        $value
                    )
                );
            }
        }
    }
}
