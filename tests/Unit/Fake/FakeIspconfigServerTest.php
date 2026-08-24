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
        self::assertSame(0, $envelope['response']);

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

    public function testClientUpdateMergesAndSkipsEmptyPassword(): void
    {
        $sid = $this->login();
        $clientId = $this->addClient($sid, 'merger');

        // Partial update: only locked changes; empty password must NOT wipe the old one
        // (remoting.inc.php updateQueryPrepare drops empty PASSWORD-type fields).
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
        self::assertSame('Str0ngPass!', $record['password']);
        self::assertSame('Jane Doe', $record['contact_name']); // merged, not lost

        // A non-empty password DOES update.
        $envelope = $this->call('client_update', [
            'session_id' => $sid,
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['password' => 'Replaced1!'],
        ]);
        self::assertSame('ok', $envelope['code']);
        self::assertSame('Replaced1!', $this->fake->clients()[$clientId]['password']);
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
        self::assertSame(7, $envelope['response']);
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
}
