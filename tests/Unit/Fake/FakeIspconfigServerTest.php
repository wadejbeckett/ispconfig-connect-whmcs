<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Fake;

use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Self-test of the behavioral double for remote/json.php + remoting.inc.php
 * (ISPConfig 3.3.1p1). Fault message strings asserted here are copied verbatim
 * from interface/lib/classes/remoting.inc.php and remote.d/*.inc.php - they are
 * the wire contract the whole suite depends on.
 */
#[CoversNothing]
final class FakeIspconfigServerTest extends TestCase
{
    private FakeIspconfigServer $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeIspconfigServer('remote', 'secret');
    }

    /**
     * POST the given params to a method and decode the JSON envelope.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call(string $method, array $params): array
    {
        $body = $this->fake->post($method, json_encode($params, JSON_THROW_ON_ERROR));
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, 'response body is not a JSON envelope: ' . $body);

        return $decoded;
    }

    private function login(): string
    {
        $envelope = $this->call('login', ['username' => 'remote', 'password' => 'secret']);
        self::assertSame('ok', $envelope['code'], 'login failed: ' . ($envelope['message'] ?? ''));

        return $envelope['response'];
    }

    // ---- envelope ---------------------------------------------------------

    public function testOkEnvelopeShape(): void
    {
        $envelope = $this->call('login', ['username' => 'remote', 'password' => 'secret']);

        self::assertSame(['code', 'message', 'response'], array_keys($envelope));
        self::assertSame('ok', $envelope['code']);
        self::assertSame('', $envelope['message']);
        self::assertIsString($envelope['response']);
        // Session id format: one lowercase letter + 40 hex chars (remoting.inc.php login()).
        self::assertMatchesRegularExpression('/^[a-z][0-9a-f]{40}$/', $envelope['response']);
    }

    public function testRemoteFaultEnvelopeShape(): void
    {
        $envelope = $this->call('login', ['username' => 'remote', 'password' => 'wrong']);

        self::assertSame('remote_fault', $envelope['code']);
        self::assertSame('The login failed. Username or password wrong.', $envelope['message']);
        self::assertFalse($envelope['response']);
    }

    public function testMethodNameIsFirstQueryStringKey(): void
    {
        $sid = $this->login();
        // Extra query pairs after the first key are ignored, exactly like $_GET parsing.
        $envelope = $this->call('logout&foo=bar', ['session_id' => $sid]);

        self::assertSame('ok', $envelope['code']);
        self::assertTrue($envelope['response']);
    }

    public function testEmptyQueryStringIsInvalidMethod(): void
    {
        $envelope = $this->call('', []);

        self::assertSame('invalid_method', $envelope['code']);
        self::assertSame('Method not provided in json call', $envelope['message']);
    }

    public function testUnknownMethodIsInvalidMethod(): void
    {
        $envelope = $this->call('no_such_method', []);

        self::assertSame('invalid_method', $envelope['code']);
        self::assertSame('Method no_such_method does not exist', $envelope['message']);
    }

    public function testNonJsonBodyIsInvalidData(): void
    {
        $body = $this->fake->post('login', 'this is not json');
        $envelope = json_decode($body, true);

        self::assertSame('invalid_data', $envelope['code']);
        self::assertSame('The JSON data sent to the api is invalid', $envelope['message']);
    }

    public function testNamedParamsAreOrderIndependentAndMissingOnesArriveAsNull(): void
    {
        // Params in "wrong" order still map by name.
        $envelope = $this->call('login', ['password' => 'secret', 'username' => 'remote']);
        self::assertSame('ok', $envelope['code']);

        // Missing param arrives as null even though the PHP signature has a
        // default (json_handler.inc.php reflection loop passes null explicitly).
        $sid = $this->login();
        $envelope = $this->call('monitor_jobqueue_count', ['session_id' => $sid]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame('0', $envelope['response']);

        // Missing password on login arrives as null -> the empty-password fault.
        $envelope = $this->call('login', ['username' => 'remote']);
        self::assertSame('remote_fault', $envelope['code']);
        self::assertSame('The login password is empty.', $envelope['message']);
    }

    public function testDisabledApiReturnsExactPlainTextBody(): void
    {
        $this->fake->apiEnabled = false;
        $body = $this->fake->post('login', json_encode(['username' => 'remote', 'password' => 'secret']));

        // Exact die() string from interface/web/remote/json.php - NOT JSON.
        self::assertSame('Remote API is disabled in security settings.', $body);
        self::assertNull(json_decode($body, true));
    }

    public function testMaintenanceModeFaultsAtLogin(): void
    {
        $this->fake->maintenanceMode = true;
        $envelope = $this->call('login', ['username' => 'remote', 'password' => 'secret']);

        self::assertSame('remote_fault', $envelope['code']);
        self::assertSame(
            'This ISPConfig installation is currently under maintenance. We should be back shortly. Thank you for your patience.',
            $envelope['message']
        );
    }

    public function testEmptyUsernameFault(): void
    {
        $envelope = $this->call('login', ['username' => '', 'password' => 'secret']);

        self::assertSame('The login username is empty.', $envelope['message']);
    }

    // ---- sessions & clock -------------------------------------------------

    public function testSessionExpiresAfterTtlViaClock(): void
    {
        $sid = $this->login();

        $this->fake->clock += 1800; // tstamp >= UNIX_TIMESTAMP() -> still valid at exactly TTL
        $envelope = $this->call('logout', ['session_id' => $sid]);
        self::assertSame('ok', $envelope['code']);

        $sid = $this->login();
        $this->fake->clock += 1801;
        $envelope = $this->call('server_get_all', ['session_id' => $sid]);
        self::assertSame('remote_fault', $envelope['code']);
        self::assertSame('The Session is expired or does not exist.', $envelope['message']);
    }

    public function testTtlIsFixedAtLoginAndNeverExtendedByUse(): void
    {
        $sid = $this->login();

        $this->fake->clock += 1000;
        $envelope = $this->call('server_get_all', ['session_id' => $sid]);
        self::assertSame('ok', $envelope['code']);

        // 1000 + 900 = 1900s since login: dead, even though the session was
        // used at t+1000 (tstamp is written once at login, remoting.inc.php).
        $this->fake->clock += 900;
        $envelope = $this->call('server_get_all', ['session_id' => $sid]);
        self::assertSame('The Session is expired or does not exist.', $envelope['message']);
    }

    public function testLogoutInvalidatesSession(): void
    {
        $sid = $this->login();

        $envelope = $this->call('logout', ['session_id' => $sid]);
        self::assertSame('ok', $envelope['code']);
        self::assertTrue($envelope['response']);

        $envelope = $this->call('server_get_all', ['session_id' => $sid]);
        self::assertSame('The Session is expired or does not exist.', $envelope['message']);
    }

    public function testUnknownSessionFaultsAndIncrementsFailureCounter(): void
    {
        $envelope = $this->call('server_get_all', ['session_id' => 'a' . str_repeat('0', 40)]);

        self::assertSame('The Session is expired or does not exist.', $envelope['message']);
        self::assertSame(1, $this->fake->failedAttempts());
    }

    // ---- lockout ----------------------------------------------------------

    public function testLockoutAfterTenBadSessionIds(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $envelope = $this->call('server_get_all', ['session_id' => 'a' . str_repeat('0', 40)]);
            self::assertSame('The Session is expired or does not exist.', $envelope['message']);
        }

        // 11th check trips the session-path lockout message.
        $envelope = $this->call('server_get_all', ['session_id' => 'a' . str_repeat('0', 40)]);
        self::assertSame('The Session failure limit has been reached.', $envelope['message']);

        // The same counter also locks the login path - even with valid credentials.
        $envelope = $this->call('login', ['username' => 'remote', 'password' => 'secret']);
        self::assertSame('The login failure limit has been reached.', $envelope['message']);
    }

    public function testLockoutAfterTenFailedLogins(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $envelope = $this->call('login', ['username' => 'remote', 'password' => 'wrong']);
            self::assertSame('The login failed. Username or password wrong.', $envelope['message']);
        }

        $envelope = $this->call('login', ['username' => 'remote', 'password' => 'secret']);
        self::assertSame('The login failure limit has been reached.', $envelope['message']);
    }

    public function testFailureWindowExpiresAfterFiveMinutes(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->call('login', ['username' => 'remote', 'password' => 'wrong']);
        }

        $this->fake->clock += 301; // failures outside the 5-minute window no longer count
        $envelope = $this->call('login', ['username' => 'remote', 'password' => 'secret']);
        self::assertSame('ok', $envelope['code']);
    }

    public function testSuccessfulLoginClearsFailureCounter(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->call('login', ['username' => 'remote', 'password' => 'wrong']);
        }
        self::assertSame(9, $this->fake->failedAttempts());

        $this->login();
        self::assertSame(0, $this->fake->failedAttempts());

        // Fresh counter: one more bad login is failure #1, not #10.
        $this->call('login', ['username' => 'remote', 'password' => 'wrong']);
        $envelope = $this->call('login', ['username' => 'remote', 'password' => 'secret']);
        self::assertSame('ok', $envelope['code']);
    }

    // ---- grants -----------------------------------------------------------

    public function testGrantAliasServerGetCoversServerGetAll(): void
    {
        $this->fake->setGrants(['server_get']);
        $this->fake->addServer('web01');
        $sid = $this->login();

        $envelope = $this->call('server_get_all', ['session_id' => $sid]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame([['server_id' => '1', 'server_name' => 'web01']], $envelope['response']);
    }

    public function testMissingGrantIsPermissionDenied(): void
    {
        $this->fake->setGrants(['client_get']);
        $sid = $this->login();

        $envelope = $this->call('server_get_all', ['session_id' => $sid]);
        self::assertSame('remote_fault', $envelope['code']);
        self::assertSame('You do not have the permissions to access this function.', $envelope['message']);
    }

    public function testClientTemplateAdditionalAddChecksClientUpdateGrant(): void
    {
        // client.inc.php: client_template_additional_add() calls checkPerm(..., 'client_update').
        $this->fake->setGrants(['client_add']);
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'alias-grant');

        $envelope = $this->call('client_template_additional_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'template_id' => 1,
        ]);
        self::assertSame('You do not have the permissions to access this function.', $envelope['message']);
    }

    // ---- client handlers --------------------------------------------------

    /** @param array<string, mixed> $extra */
    private function addClient(string $sid, string $username, array $extra = []): int
    {
        $envelope = $this->call('client_add', [
            'session_id' => $sid,
            'reseller_id' => 0,
            'params' => $extra + [
                'company_name' => 'ACME',
                'contact_name' => 'Jane Doe',
                'username' => $username,
                'password' => 'Str0ngPass!',
                'email' => $username . '@example.com',
                'language' => 'en',
                'usertheme' => 'default',
                'web_php_options' => 'no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm',
                'ssh_chroot' => 'no,jailkit',
            ],
        ]);
        self::assertSame('ok', $envelope['code'], 'client_add failed: ' . ($envelope['message'] ?? ''));

        return $envelope['response'];
    }

    public function testClientAddThenGetRoundtrip(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'acme1');
        self::assertSame(1, $clientId);

        $envelope = $this->call('client_get', ['session_id' => $sid, 'client_id' => $clientId]);
        self::assertSame('ok', $envelope['code']);
        $record = $envelope['response'];
        self::assertSame('acme1', $record['username']);
        self::assertSame('Jane Doe', $record['contact_name']);
        self::assertSame('default', $record['usertheme']);
        self::assertSame((string) $clientId, $record['client_id']);

        // Ids are deterministic and incremental.
        self::assertSame(2, $this->addClient($sid, 'acme2'));
    }

    public function testClientAddDuplicateUsernameFault(): void
    {
        $sid = $this->login();
        $this->addClient($sid, 'dupe');

        $envelope = $this->call('client_add', [
            'session_id' => $sid,
            'reseller_id' => 0,
            'params' => [
                'contact_name' => 'Other',
                'username' => 'dupe',
                'password' => 'x',
                'email' => 'other@example.com',
                'language' => 'en',
                'web_php_options' => 'php-fpm',
                'ssh_chroot' => 'no',
            ],
        ]);
        self::assertSame('remote_fault', $envelope['code']);
        // Verbatim from remoting.inc.php klientadd().
        self::assertSame('Duplicate username<br />', $envelope['message']);
    }

    public function testClientAddMissingRequiredFieldsIsDataProcessingError(): void
    {
        $sid = $this->login();
        $envelope = $this->call('client_add', [
            'session_id' => $sid,
            'reseller_id' => 0,
            'params' => ['username' => 'incomplete'],
        ]);

        self::assertSame('remote_fault', $envelope['code']);
        // The message is localized + <br />-joined; assert shape only, never text.
        self::assertStringContainsString('<br />', $envelope['message']);
    }

    public function testClientGetByUsername(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'lookup');

        $envelope = $this->call('client_get_by_username', ['session_id' => $sid, 'username' => 'lookup']);
        self::assertSame('ok', $envelope['code']);
        self::assertSame('lookup', $envelope['response']['username']);
        self::assertSame((string) $clientId, $envelope['response']['client_id']);

        $envelope = $this->call('client_get_by_username', ['session_id' => $sid, 'username' => 'ghost']);
        self::assertSame('There is no user account for this user name.', $envelope['message']);
    }

    public function testClientUpdateMergesOldRecordAndEmptyPasswordIsSkipped(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'merger');

        // Partial update with an EXPLICIT empty password: tform_base _getSQL
        // skips empty PASSWORD-type fields from the UPDATE, so the stored
        // (crypted) value survives.
        $envelope = $this->call('client_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['locked' => 'y', 'password' => ''],
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame(1, $envelope['response']);

        $record = $this->fake->clients()[$clientId];
        self::assertSame('y', $record['locked']);
        self::assertSame('crypt:Str0ngPass!', $record['password']);
        self::assertSame('Jane Doe', $record['contact_name']); // merged, not lost
    }

    // ---- client_update password corruption (finding A1) -------------------

    public function testClientAddStoresCryptedPasswordAndClientGetEchoesIt(): void
    {
        // klientadd -> getSQL INSERT crypts PASSWORD fields
        // (tform_base.inc.php); client_get returns the client row incl. the
        // stored hash. The fake marks a crypt round as 'crypt:' . <input>.
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'crypted');

        self::assertSame('crypt:Str0ngPass!', $this->fake->clients()[$clientId]['password']);

        $envelope = $this->call('client_get', ['session_id' => $sid, 'client_id' => $clientId]);
        self::assertSame('crypt:Str0ngPass!', $envelope['response']['password']);
    }

    public function testPartialClientUpdateWithoutPasswordDoubleCryptsTheStoredHash(): void
    {
        // client.inc.php client_update() merges the OLD RECORD into $params
        // before updateQuery, so the stored crypt hash lands in
        // params['password'] non-empty - and getSQL re-crypts it. Every
        // partial client_update without _ispconfig_pw_crypted corrupts the
        // panel password.
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'victim');

        $envelope = $this->call('client_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['contact_name' => 'Renamed'],
        ]);
        self::assertSame('ok', $envelope['code']);

        $record = $this->fake->clients()[$clientId];
        self::assertSame('Renamed', $record['contact_name']);
        self::assertSame('crypt:crypt:Str0ngPass!', $record['password']); // corrupted

        // Every further partial update crypts it again.
        $this->call('client_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['telephone' => '555'],
        ]);
        self::assertSame('crypt:crypt:crypt:Str0ngPass!', $this->fake->clients()[$clientId]['password']);
    }

    public function testClientUpdateWithPrecryptedFlagStoresPasswordVerbatim(): void
    {
        // _ispconfig_pw_crypted == 1 -> tform_base stores the value as-is; the
        // flag itself is not a column and must not be stored.
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'flagged');

        $envelope = $this->call('client_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['password' => '$5$prehashed$abc', '_ispconfig_pw_crypted' => 1],
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame('$5$prehashed$abc', $this->fake->clients()[$clientId]['password']);
        self::assertArrayNotHasKey('_ispconfig_pw_crypted', $this->fake->clients()[$clientId]);
    }

    public function testClientUpdateWithNewClearPasswordCryptsItOnce(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'rotator');

        $this->call('client_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['password' => 'Replaced1!'],
        ]);
        self::assertSame('crypt:Replaced1!', $this->fake->clients()[$clientId]['password']);
    }

    public function testClientGetWithNonPositiveIdFaults(): void
    {
        $sid = $this->login();
        $envelope = $this->call('client_get', ['session_id' => $sid, 'client_id' => 0]);

        // remoting_lib.inc.php getDataRecord(): id must be > 0 (or -1).
        self::assertSame('remote_fault', $envelope['code']);
        self::assertSame('The ID has to be > 0 or -1.', $envelope['message']);
    }

    public function testClientChangePassword(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'pwchange');

        $envelope = $this->call('client_change_password', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'new_password' => 'NewSecret9',
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertTrue($envelope['response']);
        // client.inc.php client_change_password() always crypts the new clear
        // (no _ispconfig_pw_crypted path there).
        self::assertSame('crypt:NewSecret9', $this->fake->clients()[$clientId]['password']);

        $envelope = $this->call('client_change_password', [
            'session_id' => $sid,
            'client_id' => 999,
            'new_password' => 'x',
        ]);
        self::assertSame('There is no user account for this client_id', $envelope['message']);
    }

    public function testClientTemplateAssignmentLifecycleAndClientGetBackfill(): void
    {
        $masterId = $this->fake->addClientTemplate('Basic', 'm');
        $addonId = $this->fake->addClientTemplate('Extra Mail', 'a');
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'tpl');

        $envelope = $this->call('client_templates_get_all', ['session_id' => $sid]);
        self::assertSame('ok', $envelope['code']);
        self::assertCount(2, $envelope['response']);
        self::assertSame('Basic', $envelope['response'][0]['template_name']);
        self::assertSame('m', $envelope['response'][0]['template_type']);
        self::assertSame((string) $masterId, $envelope['response'][0]['template_id']);

        $envelope = $this->call('client_template_additional_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'template_id' => $addonId,
        ]);
        self::assertSame('ok', $envelope['code']);
        $assignedId = $envelope['response'];
        self::assertSame(1, $assignedId);

        // client_get backfills template_additional as "assigned:template" pairs.
        $envelope = $this->call('client_get', ['session_id' => $sid, 'client_id' => $clientId]);
        self::assertSame($assignedId . ':' . $addonId, $envelope['response']['template_additional']);

        // Delete quirk (client.inc.php): the third argument is matched against
        // client_template_id, not assigned_template_id.
        $envelope = $this->call('client_template_additional_delete', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'assigned_template_id' => $addonId,
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame(1, $envelope['response']);
        self::assertSame([], $this->fake->templateAssignments());
    }

    // ---- web domain / ftp / db / mail ------------------------------------

    private function addWebDomain(string $sid, int $clientId, string $domain): int
    {
        $envelope = $this->call('sites_web_domain_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'params' => [
                'server_id' => 1,
                'domain' => $domain,
                'type' => 'vhost',
                'ip_address' => '*',
                'hd_quota' => -1,
                'traffic_quota' => -1,
                'active' => 'y',
                // Required by web_vhost_domain.tform.php REGEX validators with
                // no allowempty - values as shipped in the stock example
                // remoting_client/examples/sites_web_domain_add.php.
                'pm_process_idle_timeout' => 10,
                'pm_max_requests' => 0,
                'http_port' => '80',
                'https_port' => '443',
            ],
        ]);
        self::assertSame('ok', $envelope['code'], 'sites_web_domain_add failed: ' . ($envelope['message'] ?? ''));

        return $envelope['response'];
    }

    public function testWebDomainAddAndGetSystemUserGroupDocroot(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'webby');
        $domainId = $this->addWebDomain($sid, $clientId, 'example.com');
        self::assertSame(1, $domainId);

        $envelope = $this->call('sites_web_domain_get', ['session_id' => $sid, 'primary_id' => $domainId]);
        self::assertSame('ok', $envelope['code']);
        $record = $envelope['response'];
        self::assertSame('example.com', $record['domain']);
        self::assertSame('web1', $record['system_user']);
        self::assertSame('client' . $clientId, $record['system_group']);
        self::assertSame('/var/www/clients/client' . $clientId . '/web1', $record['document_root']);
        self::assertSame('y', $record['active']);
    }

    public function testWebDomainAddMissingRequiredFieldsIsDataProcessingError(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'nodomain');

        $envelope = $this->call('sites_web_domain_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'params' => ['server_id' => 1], // no domain
        ]);
        self::assertSame('remote_fault', $envelope['code']);
        self::assertStringContainsString('<br />', $envelope['message']);
    }

    public function testWebDomainSetStatus(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'status');
        $domainId = $this->addWebDomain($sid, $clientId, 'status.example');

        $envelope = $this->call('sites_web_domain_set_status', [
            'session_id' => $sid,
            'primary_id' => $domainId,
            'status' => 'inactive',
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame('n', $this->fake->sites()[$domainId]['active']);

        $envelope = $this->call('sites_web_domain_set_status', [
            'session_id' => $sid,
            'primary_id' => $domainId,
            'status' => 'active',
        ]);
        self::assertSame('y', $this->fake->sites()[$domainId]['active']);

        $envelope = $this->call('sites_web_domain_set_status', [
            'session_id' => $sid,
            'primary_id' => $domainId,
            'status' => 'bogus',
        ]);
        self::assertSame('The status is not available', $envelope['message']);
    }

    public function testFtpUserAdd(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'ftpc');
        $domainId = $this->addWebDomain($sid, $clientId, 'ftp.example');
        $site = $this->fake->sites()[$domainId];

        $envelope = $this->call('sites_ftp_user_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'params' => [
                'server_id' => 1,
                'parent_domain_id' => $domainId,
                'username' => 'ftpc',
                'password' => 'FtpPass1!',
                'quota_size' => -1,
                'uid' => $site['system_user'],
                'gid' => $site['system_group'],
                'dir' => $site['document_root'],
                'active' => 'y',
            ],
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame(1, $envelope['response']);
        self::assertSame('ftpc', $this->fake->ftpUsers()[1]['username']);
    }

    public function testDatabaseUserAndDatabaseAddWithDuplicateNameFault(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'dbc');

        $envelope = $this->call('sites_database_user_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'params' => ['server_id' => 1, 'database_user' => 'c1_site', 'database_password' => 'DbPass1!'],
        ]);
        self::assertSame('ok', $envelope['code']);
        $dbUserId = $envelope['response'];
        self::assertSame(1, $dbUserId);

        $dbParams = [
            'server_id' => 1,
            'type' => 'mysql',
            'database_name' => 'c1_site',
            'database_user_id' => $dbUserId,
            'active' => 'y',
        ];
        $envelope = $this->call('sites_database_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'params' => $dbParams,
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame(1, $envelope['response']);

        // Same name on the same server -> verbatim unique fault (sites.inc.php).
        $envelope = $this->call('sites_database_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'params' => $dbParams,
        ]);
        self::assertSame('There is already a database with that name on the same server.', $envelope['message']);
    }

    public function testMailDomainAdd(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'mailc');

        $envelope = $this->call('mail_domain_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'params' => ['server_id' => 1, 'domain' => 'mail.example', 'active' => 'y'],
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame(1, $envelope['response']);
        self::assertSame('mail.example', $this->fake->mailDomains()[1]['domain']);
    }

    // ---- DNS --------------------------------------------------------------

    public function testDnsTemplatezoneAddReturnsBoolAndZoneIsResolvableById(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'dnsc');

        $envelope = $this->call('dns_templatezone_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'template_id' => 1,
            'domain' => 'zone.example',
            'ip' => '203.0.113.10',
            'ns1' => 'ns1.example.net',
            'ns2' => 'ns2.example.net',
            'email' => 'admin@zone.example',
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertTrue($envelope['response']);

        // Stored origin carries the trailing dot (default template: origin={DOMAIN}.).
        self::assertSame('zone.example.', $this->fake->dnsZones()[1]['origin']);

        // Lookup WITHOUT trailing dot matches origin or origin+'.' (dns.inc.php).
        $envelope = $this->call('dns_zone_get_id', ['session_id' => $sid, 'origin' => 'zone.example']);
        self::assertSame('ok', $envelope['code']);
        self::assertSame(1, $envelope['response']);

        // Lookup WITH trailing dot fails the source regex -> 'Invalid domain name.'.
        $envelope = $this->call('dns_zone_get_id', ['session_id' => $sid, 'origin' => 'zone.example.']);
        self::assertSame('remote_fault', $envelope['code']);
        self::assertSame('Invalid domain name.', $envelope['message']);

        // Unknown zone -> verbatim not-found fault.
        $envelope = $this->call('dns_zone_get_id', ['session_id' => $sid, 'origin' => 'missing.example']);
        self::assertSame('There is no domain ID with informed domain name.', $envelope['message']);
    }

    public function testDnsTemplatezoneAddForUnknownClientFaults(): void
    {
        $sid = $this->login();
        $envelope = $this->call('dns_templatezone_add', [
            'session_id' => $sid,
            'client_id' => 999,
            'template_id' => 1,
            'domain' => 'zone.example',
            'ip' => '203.0.113.10',
            'ns1' => 'ns1.example.net',
            'ns2' => 'ns2.example.net',
            'email' => 'admin@zone.example',
        ]);

        self::assertSame('There is no group for this client ID.', $envelope['message']);
    }

    // ---- cascade delete ---------------------------------------------------

    public function testClientDeleteEverythingCascades(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'cascade');
        $keptClientId = $this->addClient($sid, 'kept');
        $domainId = $this->addWebDomain($sid, $clientId, 'cascade.example');
        $keptDomainId = $this->addWebDomain($sid, $keptClientId, 'kept.example');
        $site = $this->fake->sites()[$domainId];

        $this->call('sites_ftp_user_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'params' => [
                'server_id' => 1, 'parent_domain_id' => $domainId, 'username' => 'cascade',
                'password' => 'x', 'quota_size' => -1,
                'uid' => $site['system_user'], 'gid' => $site['system_group'], 'dir' => $site['document_root'],
            ],
        ]);
        $this->call('mail_domain_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'params' => ['server_id' => 1, 'domain' => 'cascade.example'],
        ]);
        $this->call('dns_templatezone_add', [
            'session_id' => $sid, 'client_id' => $clientId, 'template_id' => 1,
            'domain' => 'cascade.example', 'ip' => '203.0.113.10',
            'ns1' => 'ns1.example.net', 'ns2' => 'ns2.example.net', 'email' => 'admin@cascade.example',
        ]);
        $this->call('sites_database_user_add', [
            'session_id' => $sid, 'client_id' => $clientId,
            'params' => ['server_id' => 1, 'database_user' => 'c1_cascade', 'database_password' => 'x'],
        ]);
        $this->call('sites_database_add', [
            'session_id' => $sid, 'client_id' => $clientId,
            'params' => ['server_id' => 1, 'database_name' => 'c1_cascade', 'database_user_id' => 1],
        ]);
        $addonId = $this->fake->addClientTemplate('Addon', 'a');
        $this->call('client_template_additional_add', [
            'session_id' => $sid, 'client_id' => $clientId, 'template_id' => $addonId,
        ]);

        $envelope = $this->call('client_delete_everything', ['session_id' => $sid, 'client_id' => $clientId]);
        self::assertSame('ok', $envelope['code']);

        self::assertArrayNotHasKey($clientId, $this->fake->clients());
        self::assertArrayHasKey($keptClientId, $this->fake->clients());
        self::assertArrayNotHasKey($domainId, $this->fake->sites());
        self::assertArrayHasKey($keptDomainId, $this->fake->sites());
        self::assertSame([], $this->fake->ftpUsers());
        self::assertSame([], $this->fake->mailDomains());
        self::assertSame([], $this->fake->dnsZones());
        self::assertSame([], $this->fake->databases());
        self::assertSame([], $this->fake->databaseUsers());
        self::assertSame([], $this->fake->templateAssignments());
    }

    // ---- misc handlers & hooks -------------------------------------------

    public function testMonitorJobqueueCountIsConfigurable(): void
    {
        $this->fake->jobqueueCount = 7;
        $sid = $this->login();

        $envelope = $this->call('monitor_jobqueue_count', ['session_id' => $sid, 'server_id' => 1]);
        self::assertSame('ok', $envelope['code']);
        // monitor.inc.php returns $tmp['jobqueue_count'] straight from mysqli -
        // a STRING, like every other column value.
        self::assertSame('7', $envelope['response']);
    }

    public function testGetFunctionListReturnsAllRegisteredMethodsRegardlessOfGrants(): void
    {
        $this->fake->setGrants(['get_function_list']);
        $sid = $this->login();

        $envelope = $this->call('get_function_list', ['session_id' => $sid]);
        self::assertSame('ok', $envelope['code']);
        self::assertContains('client_add', $envelope['response']);
        self::assertContains('server_get_all', $envelope['response']);
        self::assertContains('dns_zone_get_id', $envelope['response']);
    }

    public function testQuotaFunctionsReturnConfigurableData(): void
    {
        $this->fake->quotaData = [['domain_id' => '1', 'used' => '100']];
        $this->fake->trafficQuotaData = ['this_month' => '42'];
        $sid = $this->login();

        $envelope = $this->call('quota_get_by_user', ['session_id' => $sid, 'client_id' => 1]);
        self::assertSame([['domain_id' => '1', 'used' => '100']], $envelope['response']);

        $envelope = $this->call('trafficquota_get_by_user', ['session_id' => $sid, 'client_id' => 1]);
        self::assertSame(['this_month' => '42'], $envelope['response']);
    }

    public function testFailNextWithInjectsOneShotFault(): void
    {
        $this->fake->addServer('web01');
        $sid = $this->login();

        $this->fake->failNextWith('server_get_all', 'database_error: gone away');
        $envelope = $this->call('server_get_all', ['session_id' => $sid]);
        self::assertSame('remote_fault', $envelope['code']);
        self::assertSame('database_error: gone away', $envelope['message']);

        // One-shot: next call succeeds again.
        $envelope = $this->call('server_get_all', ['session_id' => $sid]);
        self::assertSame('ok', $envelope['code']);
    }

    public function testRequestLogRecordsMethodAndDecodedParams(): void
    {
        $sid = $this->login();
        $this->call('monitor_jobqueue_count', ['session_id' => $sid, 'server_id' => 3]);

        $last = $this->fake->requestLog[array_key_last($this->fake->requestLog)];
        self::assertSame('monitor_jobqueue_count', $last['method']);
        self::assertSame(['session_id' => $sid, 'server_id' => 3], $last['params']);
        self::assertSame('login', $this->fake->requestLog[0]['method']);
    }

    // ---- client_update template resync (finding A2) -----------------------

    /** Assign an addon template through the API and return the assigned id. */
    private function assignTemplate(string $sid, int $clientId, int $templateId): int
    {
        $envelope = $this->call('client_template_additional_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'template_id' => $templateId,
        ]);
        self::assertSame('ok', $envelope['code']);

        return $envelope['response'];
    }

    public function testPartialClientUpdateWipesApiMadeTemplateAssignments(): void
    {
        // client_update pre-merges the stored record, whose template_additional
        // COLUMN is '' when assignments were made via the API - and
        // update_client_templates(client_id, explode('/', '')) deletes every
        // client_template_assigned row (client_templates.inc.php old-style
        // count-diff: every db row decrements to -1).
        $addonId = $this->fake->addClientTemplate('Addon', 'a');
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'wiped');
        $this->assignTemplate($sid, $clientId, $addonId);
        self::assertCount(1, $this->fake->templateAssignments());

        $envelope = $this->call('client_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['contact_name' => 'Innocent Rename'],
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame([], $this->fake->templateAssignments()); // all gone
    }

    public function testClientUpdateOldStyleBareIdsAreACountDiff(): void
    {
        $tplA = $this->fake->addClientTemplate('A', 'a');
        $tplB = $this->fake->addClientTemplate('B', 'a');
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'oldstyle');
        $assignedA = $this->assignTemplate($sid, $clientId, $tplA);

        // Passing the CURRENT bare ids is an exact no-op: +1 for the param,
        // -1 for the db row (client_templates.inc.php old-style branch).
        $this->call('client_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['template_additional' => (string) $tplA],
        ]);
        $rows = array_values($this->fake->templateAssignments());
        self::assertCount(1, $rows);
        self::assertSame($assignedA, $rows[0]['assigned_template_id']); // same row kept

        // Passing the DESIRED set applies the delta atomically: A kept, B added.
        $this->call('client_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['template_additional' => $tplA . '/' . $tplB],
        ]);
        $templateIds = array_column(array_values($this->fake->templateAssignments()), 'client_template_id');
        sort($templateIds);
        self::assertSame([$tplA, $tplB], $templateIds);

        // Dropping A from the desired set deletes exactly one A row.
        $this->call('client_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['template_additional' => (string) $tplB],
        ]);
        $templateIds = array_column(array_values($this->fake->templateAssignments()), 'client_template_id');
        self::assertSame([$tplB], $templateIds);
    }

    public function testClientUpdateNewStylePairsKeepByAssignedIdAndDeleteTheRest(): void
    {
        $tplA = $this->fake->addClientTemplate('A', 'a');
        $tplB = $this->fake->addClientTemplate('B', 'a');
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'newstyle');
        $assignedA = $this->assignTemplate($sid, $clientId, $tplA);
        $this->assignTemplate($sid, $clientId, $tplB);

        // "assignedId:templateId" pairs: rows whose assigned_template_id is
        // NOT listed are deleted; 'n'-prefixed assigned ids insert new rows.
        $this->call('client_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['template_additional' => $assignedA . ':' . $tplA . '/n1:' . $tplB],
        ]);
        $rows = array_values($this->fake->templateAssignments());
        self::assertCount(2, $rows);
        self::assertSame($assignedA, $rows[0]['assigned_template_id']); // kept, same row
        self::assertSame($tplB, $rows[1]['client_template_id']);        // re-inserted, NEW row
        self::assertNotSame(2, $rows[1]['assigned_template_id']);
    }

    public function testClientUpdateResetsTemplateAdditionalColumnToEmpty(): void
    {
        // client.inc.php: params['template_additional'] = '' before
        // updateQuery - the column stays '', assignments live in the assigned
        // table, and client_get keeps backfilling pairs from there.
        $tplA = $this->fake->addClientTemplate('A', 'a');
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'colreset');

        $this->call('client_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['template_additional' => (string) $tplA],
        ]);
        self::assertSame('', $this->fake->clients()[$clientId]['template_additional']);

        $rows = array_values($this->fake->templateAssignments());
        self::assertCount(1, $rows);
        $envelope = $this->call('client_get', ['session_id' => $sid, 'client_id' => $clientId]);
        self::assertSame(
            $rows[0]['assigned_template_id'] . ':' . $tplA,
            $envelope['response']['template_additional']
        );
    }

    // ---- locked / canceled cascade (finding A3) ---------------------------

    /**
     * Client with one site, ftp user, mail domain and database.
     *
     * @return array{sid: string, clientId: int, domainId: int}
     */
    private function clientWithObjects(string $username): array
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, $username);
        $domainId = $this->addWebDomain($sid, $clientId, $username . '.example');
        $site = $this->fake->sites()[$domainId];
        $this->call('sites_ftp_user_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'params' => [
                'server_id' => 1, 'parent_domain_id' => $domainId, 'username' => $username,
                'password' => 'FtpPass1!', 'quota_size' => -1,
                'uid' => $site['system_user'], 'gid' => $site['system_group'], 'dir' => $site['document_root'],
            ],
        ]);
        $this->call('mail_domain_add', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'params' => ['server_id' => 1, 'domain' => $username . '.example'],
        ]);
        $this->call('sites_database_user_add', [
            'session_id' => $sid, 'client_id' => $clientId,
            'params' => ['server_id' => 1, 'database_user' => 'c_' . $username, 'database_password' => 'x'],
        ]);
        $this->call('sites_database_add', [
            'session_id' => $sid, 'client_id' => $clientId,
            'params' => ['server_id' => 1, 'database_name' => 'c_' . $username, 'database_user_id' => 1],
        ]);

        return ['sid' => $sid, 'clientId' => $clientId, 'domainId' => $domainId];
    }

    /** @param array<string, mixed> $params */
    private function updateClient(string $sid, int $clientId, array $params): void
    {
        $envelope = $this->call('client_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => $params,
        ]);
        self::assertSame('ok', $envelope['code'], 'client_update failed: ' . ($envelope['message'] ?? ''));
    }

    /** @return array<string, string> store name -> active flag of the first record */
    private function activeFlags(int $clientId): array
    {
        $flags = [];
        foreach (['sites', 'ftpUsers', 'mailDomains', 'databases'] as $store) {
            foreach ($this->fake->{$store}() as $record) {
                if (($record['client_id'] ?? null) === $clientId) {
                    $flags[$store] = $record['active'];
                    break;
                }
            }
        }

        return $flags;
    }

    public function testLockedCascadeDisablesAllClientObjects(): void
    {
        ['sid' => $sid, 'clientId' => $clientId] = $this->clientWithObjects('lockme');

        $this->updateClient($sid, $clientId, ['locked' => 'y']);

        self::assertSame(
            ['sites' => 'n', 'ftpUsers' => 'n', 'mailDomains' => 'n', 'databases' => 'n'],
            $this->activeFlags($clientId)
        );
    }

    public function testUnlockRestoresEverythingExceptPreviouslyInactiveObjects(): void
    {
        ['sid' => $sid, 'clientId' => $clientId, 'domainId' => $domainId] = $this->clientWithObjects('remember');

        // The site is inactive BEFORE the lock - func_client_lock remembers it
        // in client.tmp_data and the unlock must NOT re-enable it.
        $this->call('sites_web_domain_set_status', [
            'session_id' => $sid, 'primary_id' => $domainId, 'status' => 'inactive',
        ]);

        $this->updateClient($sid, $clientId, ['locked' => 'y']);
        $this->updateClient($sid, $clientId, ['locked' => 'n']);

        self::assertSame(
            ['sites' => 'n', 'ftpUsers' => 'y', 'mailDomains' => 'y', 'databases' => 'y'],
            $this->activeFlags($clientId)
        );
    }

    public function testRelockingAnAlreadyLockedClientPoisonsTmpData(): void
    {
        ['sid' => $sid, 'clientId' => $clientId] = $this->clientWithObjects('poison');

        $this->updateClient($sid, $clientId, ['locked' => 'y']);
        // Second lock: every object is ALREADY inactive, so func_client_lock
        // overwrites tmp_data remembering ALL of them as previously-inactive.
        $this->updateClient($sid, $clientId, ['locked' => 'y']);
        // The later unlock therefore restores NOTHING.
        $this->updateClient($sid, $clientId, ['locked' => 'n']);

        self::assertSame(
            ['sites' => 'n', 'ftpUsers' => 'n', 'mailDomains' => 'n', 'databases' => 'n'],
            $this->activeFlags($clientId)
        );
    }

    public function testPartialUpdateOnUnlockedClientReenablesManuallyDisabledObjects(): void
    {
        // client.inc.php: if($params['locked']) runs on the MERGED record, and
        // 'n' is truthy - so every update of an unlocked client runs the
        // unlock branch of func_client_lock, re-enabling objects that were
        // deliberately deactivated (tmp_data remembers nothing).
        ['sid' => $sid, 'clientId' => $clientId, 'domainId' => $domainId] = $this->clientWithObjects('sneaky');
        $this->call('sites_web_domain_set_status', [
            'session_id' => $sid, 'primary_id' => $domainId, 'status' => 'inactive',
        ]);

        $this->updateClient($sid, $clientId, ['contact_name' => 'Just a rename']);

        self::assertSame('y', $this->fake->sites()[$domainId]['active']);
    }

    public function testCanceledTogglesSysUserActiveAndSticksAcrossPartialUpdates(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'cancelme');

        $envelope = $this->call('client_get_by_username', ['session_id' => $sid, 'username' => 'cancelme']);
        self::assertSame('1', $envelope['response']['active']);

        // func_client_cancel: canceled='y' -> sys_user.active = 0.
        $this->updateClient($sid, $clientId, ['canceled' => 'y']);
        $envelope = $this->call('client_get_by_username', ['session_id' => $sid, 'username' => 'cancelme']);
        self::assertSame('0', $envelope['response']['active']);

        // Pre-merge keeps canceled='y' on partial updates - stays disabled.
        $this->updateClient($sid, $clientId, ['contact_name' => 'Still canceled']);
        $envelope = $this->call('client_get_by_username', ['session_id' => $sid, 'username' => 'cancelme']);
        self::assertSame('0', $envelope['response']['active']);

        $this->updateClient($sid, $clientId, ['canceled' => 'n']);
        $envelope = $this->call('client_get_by_username', ['session_id' => $sid, 'username' => 'cancelme']);
        self::assertSame('1', $envelope['response']['active']);
    }

    // ---- client_template_additional_get (finding B) -----------------------

    public function testClientTemplateAdditionalGetReturnsAssignedRowsStringTyped(): void
    {
        $addonId = $this->fake->addClientTemplate('Addon', 'a');
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'lister');

        // No assignments -> empty list (queryAllRecords on zero rows).
        $envelope = $this->call('client_template_additional_get', [
            'session_id' => $sid, 'client_id' => $clientId,
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame([], $envelope['response']);

        $assignedId = $this->assignTemplate($sid, $clientId, $addonId);
        $envelope = $this->call('client_template_additional_get', [
            'session_id' => $sid, 'client_id' => $clientId,
        ]);
        self::assertSame([[
            'assigned_template_id' => (string) $assignedId,
            'client_id' => (string) $clientId,
            'client_template_id' => (string) $addonId,
        ]], $envelope['response']);
    }

    public function testClientTemplateAdditionalGetChecksClientGetGrant(): void
    {
        // client.inc.php: checkPerm(..., 'client_get') - an alias grant.
        $this->fake->setGrants(['client_get']);
        $sid = $this->login();

        $envelope = $this->call('client_template_additional_get', [
            'session_id' => $sid, 'client_id' => 1,
        ]);
        self::assertSame('ok', $envelope['code']);

        $envelope = $this->call('client_template_additional_get', [
            'session_id' => $sid, 'client_id' => 'not-a-number',
        ]);
        self::assertSame('remote_fault', $envelope['code']);
        self::assertSame('The ID must be an integer.', $envelope['message']);

        $this->fake->setGrants(['client_update']);
        $sid = $this->login();
        $envelope = $this->call('client_template_additional_get', [
            'session_id' => $sid, 'client_id' => 1,
        ]);
        self::assertSame('You do not have the permissions to access this function.', $envelope['message']);
    }

    // ---- sites_ftp_user_update / sites_database_user_update (finding C) ---

    public function testFtpUserUpdatePartialMergeDropsEmptyPasswordAndIsSafe(): void
    {
        // sites.inc.php sites_ftp_user_update() uses plain updateQuery - NO
        // client_update-style pre-merge, so updateQueryPrepare's empty-
        // PASSWORD drop makes partial updates safe.
        ['sid' => $sid, 'clientId' => $clientId] = $this->clientWithObjects('ftpsafe');

        self::assertSame('crypt:FtpPass1!', $this->fake->ftpUsers()[1]['password']);

        $envelope = $this->call('sites_ftp_user_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'primary_id' => 1,
            'params' => ['quota_size' => 500],
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame(1, $envelope['response']);

        $record = $this->fake->ftpUsers()[1];
        self::assertSame(500, $record['quota_size']);
        self::assertSame('ftpsafe', $record['username']);            // merged, kept
        self::assertSame('crypt:FtpPass1!', $record['password']);    // NOT double-crypted

        // Explicit empty password is dropped too.
        $this->call('sites_ftp_user_update', [
            'session_id' => $sid, 'client_id' => $clientId, 'primary_id' => 1,
            'params' => ['password' => ''],
        ]);
        self::assertSame('crypt:FtpPass1!', $this->fake->ftpUsers()[1]['password']);

        // A new clear password is crypted once.
        $this->call('sites_ftp_user_update', [
            'session_id' => $sid, 'client_id' => $clientId, 'primary_id' => 1,
            'params' => ['password' => 'NewFtp2!'],
        ]);
        self::assertSame('crypt:NewFtp2!', $this->fake->ftpUsers()[1]['password']);
    }

    public function testFtpUserUpdateChecksOwnGrantAndUnknownIdAffectsZeroRows(): void
    {
        $envelope = null;
        $this->fake->setGrants(['sites_ftp_user_update']);
        $sid = $this->login();

        $envelope = $this->call('sites_ftp_user_update', [
            'session_id' => $sid, 'client_id' => 1, 'primary_id' => 99, 'params' => ['quota_size' => 1],
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame(0, $envelope['response']);

        $this->fake->setGrants(['sites_ftp_user_add']);
        $sid = $this->login();
        $envelope = $this->call('sites_ftp_user_update', [
            'session_id' => $sid, 'client_id' => 1, 'primary_id' => 1, 'params' => [],
        ]);
        self::assertSame('You do not have the permissions to access this function.', $envelope['message']);
    }

    public function testDatabaseUserUpdatePartialMergeDropsEmptyDatabasePassword(): void
    {
        ['sid' => $sid, 'clientId' => $clientId] = $this->clientWithObjects('dbsafe');

        self::assertSame('crypt:x', $this->fake->databaseUsers()[1]['database_password']);

        $envelope = $this->call('sites_database_user_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'primary_id' => 1,
            'params' => ['database_user' => 'c_dbsafe2'],
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame(1, $envelope['response']);

        $record = $this->fake->databaseUsers()[1];
        self::assertSame('c_dbsafe2', $record['database_user']);
        self::assertSame('crypt:x', $record['database_password']); // safe partial update

        $this->call('sites_database_user_update', [
            'session_id' => $sid, 'client_id' => $clientId, 'primary_id' => 1,
            'params' => ['database_password' => 'NewDb1!'],
        ]);
        self::assertSame('crypt:NewDb1!', $this->fake->databaseUsers()[1]['database_password']);
    }

    public function testDatabaseUserUpdateChecksOwnGrant(): void
    {
        $this->fake->setGrants(['sites_database_user_add']);
        $sid = $this->login();

        $envelope = $this->call('sites_database_user_update', [
            'session_id' => $sid, 'client_id' => 1, 'primary_id' => 1, 'params' => [],
        ]);
        self::assertSame('You do not have the permissions to access this function.', $envelope['message']);
    }

    // ---- sites_web_domain_add required fields (finding D) -----------------

    public function testWebDomainAddRejectsMissingPmAndPortFields(): void
    {
        // web_vhost_domain.tform.php REGEX validators without allowempty:
        // pm_process_idle_timeout, pm_max_requests, http_port, https_port all
        // fail on empty/absent values - the stock example ships 10/0/'80'/'443'.
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'noports');

        foreach (['pm_process_idle_timeout', 'pm_max_requests', 'http_port', 'https_port'] as $missing) {
            $params = [
                'server_id' => 1, 'domain' => 'noports.example',
                'hd_quota' => -1, 'traffic_quota' => -1,
                'pm_process_idle_timeout' => 10, 'pm_max_requests' => 0,
                'http_port' => '80', 'https_port' => '443',
            ];
            unset($params[$missing]);
            $envelope = $this->call('sites_web_domain_add', [
                'session_id' => $sid, 'client_id' => $clientId, 'params' => $params,
            ]);
            self::assertSame('remote_fault', $envelope['code'], $missing . ' absent must fault');
            // data_processing_error text is localized - assert shape only.
            self::assertStringContainsString('<br />', $envelope['message']);
        }
    }

    public function testWebDomainAddValidatesPmAndPortValuesAgainstSourceRegexes(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'regexes');
        $base = [
            'server_id' => 1, 'hd_quota' => -1, 'traffic_quota' => -1,
            'pm_process_idle_timeout' => 10, 'pm_max_requests' => 0,
            'http_port' => '80', 'https_port' => '443',
        ];

        // pm_process_idle_timeout: /^([1-9][0-9]{0,10})$/ - zero is invalid.
        $envelope = $this->call('sites_web_domain_add', [
            'session_id' => $sid, 'client_id' => $clientId,
            'params' => ['domain' => 'r1.example', 'pm_process_idle_timeout' => 0] + $base,
        ]);
        self::assertSame('remote_fault', $envelope['code']);

        // http_port: /^([0-9]{1,5})$/ - six digits is invalid.
        $envelope = $this->call('sites_web_domain_add', [
            'session_id' => $sid, 'client_id' => $clientId,
            'params' => ['domain' => 'r2.example', 'http_port' => '123456'] + $base,
        ]);
        self::assertSame('remote_fault', $envelope['code']);

        // pm_max_requests: /^([0-9]{1,11})$/ - zero IS valid; full set passes.
        $envelope = $this->call('sites_web_domain_add', [
            'session_id' => $sid, 'client_id' => $clientId,
            'params' => ['domain' => 'r3.example'] + $base,
        ]);
        self::assertSame('ok', $envelope['code'], 'valid params rejected: ' . ($envelope['message'] ?? ''));
    }

    // ---- client_add language (finding E) ----------------------------------

    public function testClientAddRequiresLanguage(): void
    {
        // client.tform.php: 'language' is NOTEMPTY; the tform default only
        // fills the panel's SELECT - the remote API has no server-side
        // default, so an absent language is a validation error.
        $sid = $this->login();
        $envelope = $this->call('client_add', [
            'session_id' => $sid,
            'reseller_id' => 0,
            'params' => [
                'contact_name' => 'No Lang',
                'username' => 'nolang',
                'password' => 'Str0ngPass!',
                'email' => 'nolang@example.com',
            ],
        ]);

        self::assertSame('remote_fault', $envelope['code']);
        self::assertStringContainsString('<br />', $envelope['message']); // localized - shape only
        self::assertArrayNotHasKey(1, $this->fake->clients());
    }

    // ---- get-miss responses return null (response-shape minors) -----------

    public function testGetOnUnknownIdsReturnsNullNotFalse(): void
    {
        // remoting_lib getDataRecord -> db queryOneRecord: a miss is NULL, and
        // json.php serializes it as response: null (code stays ok).
        $sid = $this->login();

        foreach (
            [
                'client_get' => 'client_id',
                'sites_web_domain_get' => 'primary_id',
                'sites_database_get' => 'primary_id',
                'mail_domain_get' => 'primary_id',
                'dns_zone_get' => 'primary_id',
            ] as $method => $idParam
        ) {
            $envelope = $this->call($method, ['session_id' => $sid, $idParam => 999]);
            self::assertSame('ok', $envelope['code'], $method . ': ' . ($envelope['message'] ?? ''));
            self::assertNull($envelope['response'], $method . ' miss must be null');
        }
    }

    public function testSitesDatabaseMailDomainAndDnsZoneGetReturnRecords(): void
    {
        ['sid' => $sid, 'clientId' => $clientId] = $this->clientWithObjects('getters');
        $this->call('dns_templatezone_add', [
            'session_id' => $sid, 'client_id' => $clientId, 'template_id' => 1,
            'domain' => 'getters.example', 'ip' => '203.0.113.10',
            'ns1' => 'ns1.example.net', 'ns2' => 'ns2.example.net', 'email' => 'admin@getters.example',
        ]);

        $envelope = $this->call('sites_database_get', ['session_id' => $sid, 'primary_id' => 1]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame('c_getters', $envelope['response']['database_name']);

        $envelope = $this->call('mail_domain_get', ['session_id' => $sid, 'primary_id' => 1]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame('getters.example', $envelope['response']['domain']);

        $envelope = $this->call('dns_zone_get', ['session_id' => $sid, 'primary_id' => 1]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame('getters.example.', $envelope['response']['origin']);
    }
}
