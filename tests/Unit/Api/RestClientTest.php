<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Api;

use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\ApiDisabledException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\ApiProtocolException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LockoutException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LoginFailedException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\MalformedResponseException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\PermissionDeniedException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\RemoteFaultException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\SessionExpiredException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\TransportException;
use WHMCS\Module\Server\Ispconfigconnect\Api\HttpTransportInterface;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;

#[CoversClass(RestClient::class)]
final class RestClientTest extends TestCase
{
    /** @var list<array{action: string, request: array<string, mixed>, response: mixed, sensitive: list<string>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->logged = [];
    }

    private function recordingLogger(): \Closure
    {
        return function (string $action, array $request, mixed $response, array $sensitive): void {
            $this->logged[] = [
                'action' => $action,
                'request' => $request,
                'response' => $response,
                'sensitive' => $sensitive,
            ];
        };
    }

    private static function transportReturning(string $body): HttpTransportInterface
    {
        return new class ($body) implements HttpTransportInterface {
            public function __construct(private readonly string $body)
            {
            }

            public function post(string $method, string $jsonBody): string
            {
                return $this->body;
            }
        };
    }

    // ---- login happy path -------------------------------------------------

    public function testLoginReturnsSessionIdInIspconfigFormat(): void
    {
        $fake = new FakeIspconfigServer();
        $client = new RestClient($fake);

        $sessionId = $client->login('remote', 'secret');

        // 1 lowercase letter + 40 hex chars (spec 1.4).
        self::assertMatchesRegularExpression('/^[a-z][0-9a-f]{40}$/', $sessionId);
    }

    public function testLoginSendsUsernamePasswordAndClientLoginFalse(): void
    {
        $fake = new FakeIspconfigServer();
        $client = new RestClient($fake);

        $client->login('remote', 'secret');

        self::assertSame(
            [['method' => 'login', 'params' => ['username' => 'remote', 'password' => 'secret', 'client_login' => false]]],
            $fake->requestLog
        );
    }

    // ---- ok envelope ------------------------------------------------------

    public function testOkEnvelopeReturnsResponsePayload(): void
    {
        $fake = new FakeIspconfigServer();
        $fake->addServer('web01');
        $client = new RestClient($fake);
        $sessionId = $client->login('remote', 'secret');

        $servers = $client->call('server_get_all', ['session_id' => $sessionId]);

        self::assertSame([['server_id' => '1', 'server_name' => 'web01']], $servers);
    }

    // ---- remote_fault -> typed exceptions ---------------------------------

    public function testWrongCredentialsFaultBecomesLoginFailedException(): void
    {
        $client = new RestClient(new FakeIspconfigServer());

        $this->expectException(LoginFailedException::class);
        $this->expectExceptionMessage('The login failed. Username or password wrong.');
        $client->login('remote', 'wrong-password');
    }

    public function testSessionExpiredFaultBecomesSessionExpiredException(): void
    {
        $fake = new FakeIspconfigServer();
        $client = new RestClient($fake);

        // Any 41-char id the fake never issued counts as expired/unknown.
        $this->expectException(SessionExpiredException::class);
        $client->call('server_get_all', ['session_id' => 'a' . str_repeat('0', 40)]);
    }

    public function testLockoutFaultBecomesLockoutException(): void
    {
        $fake = new FakeIspconfigServer();
        $fake->failNextWith('login', 'The login failure limit has been reached.');
        $client = new RestClient($fake);

        $this->expectException(LockoutException::class);
        $client->login('remote', 'secret');
    }

    public function testMissingGrantBecomesPermissionDeniedException(): void
    {
        $fake = new FakeIspconfigServer(grants: []);
        $client = new RestClient($fake);
        $sessionId = $client->login('remote', 'secret');

        $this->expectException(PermissionDeniedException::class);
        $client->call('monitor_jobqueue_count', ['session_id' => $sessionId]);
    }

    public function testUnclassifiedFaultStaysPlainRemoteFaultException(): void
    {
        $fake = new FakeIspconfigServer();
        $fake->failNextWith('client_add', 'database_error contact_name_error_empty<br />');
        $client = new RestClient($fake);

        try {
            $client->call('client_add', ['session_id' => 'x', 'reseller_id' => 0, 'params' => []]);
            self::fail('Expected RemoteFaultException');
        } catch (RemoteFaultException $fault) {
            // Exactly the base class - unclassified faults must never be
            // promoted to a typed subclass by accident.
            self::assertSame(RemoteFaultException::class, $fault::class);
            self::assertSame('database_error contact_name_error_empty<br />', $fault->getMessage());
        }
    }

    // ---- protocol-level codes ---------------------------------------------

    public function testInvalidDataCodeBecomesApiProtocolException(): void
    {
        // Corrupt the body between client and server so the fake's JSON
        // handler path answers code=invalid_data, like json_handler.inc.php.
        $fake = new FakeIspconfigServer();
        $corrupting = new class ($fake) implements HttpTransportInterface {
            public function __construct(private readonly FakeIspconfigServer $fake)
            {
            }

            public function post(string $method, string $jsonBody): string
            {
                return $this->fake->post($method, 'this is not json');
            }
        };
        $client = new RestClient($corrupting);

        try {
            $client->call('login', ['username' => 'remote', 'password' => 'secret']);
            self::fail('Expected ApiProtocolException');
        } catch (ApiProtocolException $exception) {
            self::assertSame('invalid_data', $exception->apiCode);
        }
    }

    public function testUnknownMethodCodeBecomesApiProtocolException(): void
    {
        $client = new RestClient(new FakeIspconfigServer());

        try {
            $client->call('frobnicate_all_the_things', ['session_id' => 'x']);
            self::fail('Expected ApiProtocolException');
        } catch (ApiProtocolException $exception) {
            self::assertSame('invalid_method', $exception->apiCode);
        }
    }

    // ---- non-JSON bodies --------------------------------------------------

    public function testDisabledApiPlainTextBecomesApiDisabledException(): void
    {
        $fake = new FakeIspconfigServer();
        $fake->apiEnabled = false;
        $client = new RestClient($fake);

        $this->expectException(ApiDisabledException::class);
        $client->login('remote', 'secret');
    }

    public function testNonJsonBodyBecomesMalformedResponseException(): void
    {
        $client = new RestClient(self::transportReturning('<html><body>Maintenance page</body></html>'));

        try {
            $client->call('login', ['username' => 'remote', 'password' => 'secret']);
            self::fail('Expected MalformedResponseException');
        } catch (MalformedResponseException $exception) {
            self::assertStringContainsString('<html><body>Maintenance page</body></html>', $exception->getMessage());
        }
    }

    public function testMalformedMessageTruncatesBodyTo300Chars(): void
    {
        $body = str_repeat('A', 300) . 'ZZZ-TAIL-MARKER';
        $client = new RestClient(self::transportReturning($body));

        try {
            $client->call('login', ['username' => 'remote', 'password' => 'secret']);
            self::fail('Expected MalformedResponseException');
        } catch (MalformedResponseException $exception) {
            self::assertStringContainsString(str_repeat('A', 300), $exception->getMessage());
            self::assertStringNotContainsString('ZZZ-TAIL-MARKER', $exception->getMessage());
        }
    }

    public function testEnvelopeMissingCodeKeyIsMalformed(): void
    {
        $client = new RestClient(self::transportReturning('{"response": true}'));

        $this->expectException(MalformedResponseException::class);
        $client->call('logout', ['session_id' => 'x']);
    }

    // ---- login response validation ----------------------------------------

    public function testLoginRejectsFalseSessionResponse(): void
    {
        $client = new RestClient(self::transportReturning('{"code":"ok","message":"","response":false}'));

        $this->expectException(MalformedResponseException::class);
        $client->login('remote', 'secret');
    }

    public function testLoginRejectsEmptySessionResponse(): void
    {
        $client = new RestClient(self::transportReturning('{"code":"ok","message":"","response":""}'));

        $this->expectException(MalformedResponseException::class);
        $client->login('remote', 'secret');
    }

    // ---- logout -----------------------------------------------------------

    public function testLogoutReturnsTrueAndInvalidatesTheSession(): void
    {
        $fake = new FakeIspconfigServer();
        $client = new RestClient($fake);
        $sessionId = $client->login('remote', 'secret');

        self::assertTrue($client->logout($sessionId));
        self::assertSame(0, $fake->activeSessionCount());
    }

    // ---- request encoding -------------------------------------------------

    public function testRequestBodyEncodingKeepsSlashesAndUnicode(): void
    {
        $capture = new class implements HttpTransportInterface {
            public string $body = '';

            public function post(string $method, string $jsonBody): string
            {
                $this->body = $jsonBody;

                return '{"code":"ok","message":"","response":true}';
            }
        };
        $client = new RestClient($capture);

        $client->call('mail_domain_add', [
            'session_id' => 's1',
            'params' => ['domain' => 'sub/example.com', 'name' => 'Zürich'],
        ]);

        self::assertSame(
            '{"session_id":"s1","params":{"domain":"sub/example.com","name":"Zürich"}}',
            $capture->body
        );
    }

    // ---- logger callback --------------------------------------------------

    public function testLoggerReceivesEverySuccessfulCallWithSensitiveValues(): void
    {
        $fake = new FakeIspconfigServer();
        $fake->addServer('web01');
        $client = new RestClient($fake, $this->recordingLogger());

        $sessionId = $client->login('remote', 'secret');
        $client->call('server_get_all', ['session_id' => $sessionId]);

        self::assertCount(2, $this->logged);

        self::assertSame('login', $this->logged[0]['action']);
        // Request reaches the logger INTACT - scrubbing is the wiring's job.
        self::assertSame('secret', $this->logged[0]['request']['password']);
        self::assertSame($sessionId, $this->logged[0]['response']);
        self::assertContains('secret', $this->logged[0]['sensitive']);
        // The freshly issued session id only exists in the response, and it
        // must still land in the sensitive list.
        self::assertContains($sessionId, $this->logged[0]['sensitive']);

        self::assertSame('server_get_all', $this->logged[1]['action']);
        self::assertSame([$sessionId], $this->logged[1]['sensitive']);
    }

    public function testLoggerReceivesFailuresWithExceptionMessageAsResponse(): void
    {
        $client = new RestClient(new FakeIspconfigServer(), $this->recordingLogger());

        try {
            $client->login('remote', 'wrong-password');
            self::fail('Expected LoginFailedException');
        } catch (LoginFailedException) {
        }

        self::assertCount(1, $this->logged);
        self::assertSame('login', $this->logged[0]['action']);
        self::assertSame('The login failed. Username or password wrong.', $this->logged[0]['response']);
        self::assertContains('wrong-password', $this->logged[0]['sensitive']);
    }

    public function testLoggerFiresWhenTheTransportItselfFails(): void
    {
        $failing = new class implements HttpTransportInterface {
            public function post(string $method, string $jsonBody): string
            {
                throw new TransportException('TLS certificate verification failed');
            }
        };
        $client = new RestClient($failing, $this->recordingLogger());

        try {
            $client->call('logout', ['session_id' => 'a' . str_repeat('0', 40)]);
            self::fail('Expected TransportException');
        } catch (TransportException) {
        }

        self::assertCount(1, $this->logged);
        self::assertSame('TLS certificate verification failed', $this->logged[0]['response']);
        self::assertSame(['a' . str_repeat('0', 40)], $this->logged[0]['sensitive']);
    }

    public function testNestedSensitiveValuesAreReported(): void
    {
        $fake = new FakeIspconfigServer();
        $client = new RestClient($fake, $this->recordingLogger());
        $sessionId = $client->login('remote', 'secret');

        $client->call('client_add', [
            'session_id' => $sessionId,
            'reseller_id' => 0,
            'params' => [
                'contact_name' => 'Jane Doe',
                'username' => 'jane',
                'email' => 'jane@example.com',
                // NOTEMPTY over the remote API (client.tform.php) - no
                // server-side default.
                'language' => 'en',
                'web_php_options' => 'php-fpm',
                'ssh_chroot' => 'no',
                'password' => 'Cl13nt-Pw!',
            ],
        ]);

        $entry = $this->logged[1];
        self::assertSame('client_add', $entry['action']);
        self::assertContains($sessionId, $entry['sensitive']);
        // The client password sits one level down in `params` - it must still
        // be reported for masking.
        self::assertContains('Cl13nt-Pw!', $entry['sensitive']);
        self::assertNotContains('jane', $entry['sensitive']);
    }

    public function testDatabasePasswordParamIsReportedSensitive(): void
    {
        $client = new RestClient(
            self::transportReturning('{"code":"ok","message":"","response":5}'),
            $this->recordingLogger()
        );

        $client->call('sites_database_user_add', [
            'session_id' => 's0000',
            'client_id' => 1,
            'params' => ['database_user' => 'c1_db', 'database_password' => 'Db-S3cret!'],
        ]);

        self::assertContains('Db-S3cret!', $this->logged[0]['sensitive']);
    }

    public function testSensitiveValuesInRecordResponsesAreReported(): void
    {
        // client_get echoes the stored password column back in the record -
        // the response side must be reported for masking too.
        $envelope = json_encode([
            'code' => 'ok',
            'message' => '',
            'response' => ['client_id' => '7', 'username' => 'c7', 'password' => '$5$storedhash$abcdef'],
        ], JSON_THROW_ON_ERROR);
        $client = new RestClient(self::transportReturning($envelope), $this->recordingLogger());

        $client->call('client_get', ['session_id' => 's0000', 'client_id' => 7]);

        self::assertContains('$5$storedhash$abcdef', $this->logged[0]['sensitive']);
    }

    public function testPrivateKeyColumnsInRecordResponsesAreReportedSensitive(): void
    {
        // quota_get_by_user returns web_domain rows via SELECT * - INCLUDING
        // ssl_key (the site's TLS private key) and ssl_request
        // (install/sql/ispconfig3.sql:2036/:2033). Those must reach the
        // masking list or they land verbatim in tblmodulelog.
        $envelope = json_encode([
            'code' => 'ok',
            'message' => '',
            'response' => [[
                'domain_id' => '5',
                'domain' => 'one.example',
                'ssl_key' => "-----BEGIN PRIVATE KEY-----\nFAKE-TLS-KEY\n-----END PRIVATE KEY-----",
                'ssl_request' => "-----BEGIN CERTIFICATE REQUEST-----\nFAKE-CSR\n-----END CERTIFICATE REQUEST-----",
            ]],
        ], JSON_THROW_ON_ERROR);
        $client = new RestClient(self::transportReturning($envelope), $this->recordingLogger());

        $client->call('quota_get_by_user', ['session_id' => 's0000', 'client_id' => 1]);

        self::assertContains(
            "-----BEGIN PRIVATE KEY-----\nFAKE-TLS-KEY\n-----END PRIVATE KEY-----",
            $this->logged[0]['sensitive']
        );
        self::assertContains(
            "-----BEGIN CERTIFICATE REQUEST-----\nFAKE-CSR\n-----END CERTIFICATE REQUEST-----",
            $this->logged[0]['sensitive']
        );
    }

    public function testSshAndMailSecretColumnsInRecordResponsesAreReportedSensitive(): void
    {
        // client_get(-1) returns client rows including id_rsa - the client's
        // SSH private key (install/sql/ispconfig3.sql:260); mail_domain reads
        // carry dkim_private and relay_pass (:893/:897).
        $envelope = json_encode([
            'code' => 'ok',
            'message' => '',
            'response' => [[
                'client_id' => '7',
                'username' => 'c7',
                'id_rsa' => "-----BEGIN RSA PRIVATE KEY-----\nFAKE-SSH-KEY\n-----END RSA PRIVATE KEY-----",
                'dkim_private' => "-----BEGIN RSA PRIVATE KEY-----\nFAKE-DKIM-KEY\n-----END RSA PRIVATE KEY-----",
                'relay_pass' => 'Relay-S3cret!',
            ]],
        ], JSON_THROW_ON_ERROR);
        $client = new RestClient(self::transportReturning($envelope), $this->recordingLogger());

        $client->call('client_get', ['session_id' => 's0000', 'client_id' => -1]);

        self::assertContains(
            "-----BEGIN RSA PRIVATE KEY-----\nFAKE-SSH-KEY\n-----END RSA PRIVATE KEY-----",
            $this->logged[0]['sensitive']
        );
        self::assertContains(
            "-----BEGIN RSA PRIVATE KEY-----\nFAKE-DKIM-KEY\n-----END RSA PRIVATE KEY-----",
            $this->logged[0]['sensitive']
        );
        self::assertContains('Relay-S3cret!', $this->logged[0]['sensitive']);
    }

    public function testThrowingLoggerNeverBreaksTheApiCall(): void
    {
        $fake = new FakeIspconfigServer();
        $client = new RestClient($fake, static function (): void {
            throw new \RuntimeException('tblmodulelog write failed');
        });

        // Success path: the call must still return its result.
        $sessionId = $client->login('remote', 'secret');
        self::assertMatchesRegularExpression('/^[a-z][0-9a-f]{40}$/', $sessionId);

        // Failure path: the ORIGINAL error must surface, not the logger's.
        $fake->failNextWith('logout', 'The Session is expired or does not exist.');
        $this->expectException(SessionExpiredException::class);
        $client->logout($sessionId);
    }

    public function testJsonEncodeFailureIsLoggedAndRethrown(): void
    {
        $client = new RestClient(new FakeIspconfigServer(), $this->recordingLogger());

        try {
            $client->call('client_add', ['session_id' => 'x', 'reseller_id' => NAN, 'params' => []]);
            self::fail('Expected JsonException');
        } catch (\JsonException) {
        }

        self::assertCount(1, $this->logged);
        self::assertSame('client_add', $this->logged[0]['action']);
        self::assertIsString($this->logged[0]['response']);
    }

    // ---- scrubbing the copy that goes to the log --------------------------

    public function testTheClientRecordReachesTheLoggerWithoutTheCustomerRecord(): void
    {
        $fake = new FakeIspconfigServer();
        $clientId = $fake->addClient('panelcust');
        $client = new RestClient($fake, $this->recordingLogger());
        $sessionId = $client->login('remote', 'secret');

        $record = $client->call('client_get', ['session_id' => $sessionId, 'client_id' => $clientId]);

        // The MODULE gets the panel's answer in full - scrubbing is for the
        // log copy only, never for the caller.
        self::assertIsArray($record);
        $seeded = FakeIspconfigServer::clientCustomerRecord($clientId);
        self::assertSame($seeded['bank_account_iban'], $record['bank_account_iban']);
        self::assertSame($seeded['street'], $record['street']);

        $logged = $this->logged[1]['response'];
        self::assertIsArray($logged);
        // Gone, not masked: money, tax id, postal address, free-text notes.
        foreach (
            [
                'bank_account_owner', 'bank_account_number', 'bank_code', 'bank_name',
                'bank_account_iban', 'bank_account_swift', 'paypal_email', 'vat_id',
                'activation_code', 'street', 'zip', 'city', 'telephone', 'notes',
                'contact_firstname', 'company_name', 'customer_no',
            ] as $column
        ) {
            self::assertArrayNotHasKey($column, $logged, 'client.' . $column . ' reached the log');
        }
        // Kept, because the module reads them and a log entry that identifies
        // nothing is worth nothing.
        self::assertSame($record['client_id'], $logged['client_id']);
        self::assertSame($record['username'], $logged['username']);
        self::assertSame($record['email'], $logged['email']);
        self::assertSame($record['locked'], $logged['locked']);
        self::assertSame($record['template_master'], $logged['template_master']);
        // Kept and MASKED: key material is high-entropy, so replaceVars is an
        // exact control, and seeing the column tells the operator the panel
        // returned a private key at all.
        self::assertSame($record['id_rsa'], $logged['id_rsa']);
        self::assertContains($record['id_rsa'], $this->logged[1]['sensitive']);
        // The dropped columns are accounted for rather than silently missing.
        self::assertArrayHasKey(RestClient::SCRUB_NOTICE_KEY, $logged);
    }

    public function testTheWildcardClientGetIsProjectedRowByRow(): void
    {
        // client_get(-1) is SELECT * FROM client - EVERY client on the panel
        // in one response, so the projection has to reach into each row.
        $fake = new FakeIspconfigServer();
        $first = $fake->addClient('panelcust1');
        $second = $fake->addClient('panelcust2');
        $client = new RestClient($fake, $this->recordingLogger());
        $sessionId = $client->login('remote', 'secret');

        $client->call('client_get', ['session_id' => $sessionId, 'client_id' => -1]);

        $logged = $this->logged[1]['response'];
        self::assertIsArray($logged);
        self::assertCount(2, $logged);
        foreach ($logged as $row) {
            self::assertIsArray($row);
            self::assertArrayNotHasKey('bank_account_iban', $row);
            self::assertArrayNotHasKey('paypal_email', $row);
            self::assertArrayHasKey('client_id', $row);
        }
        self::assertSame((string) $first, $logged[0]['client_id']);
        self::assertSame((string) $second, $logged[1]['client_id']);
    }

    public function testTheSysUserRowReachesTheLoggerWithoutItsPrivateColumns(): void
    {
        // client_get_by_username is a bare SELECT * FROM sys_user
        // (remote.d/client.inc.php:478).
        $fake = new FakeIspconfigServer();
        $clientId = $fake->addClient('panelcust');
        $client = new RestClient($fake, $this->recordingLogger());
        $sessionId = $client->login('remote', 'secret');

        $row = $client->call('client_get_by_username', [
            'session_id' => $sessionId,
            'username' => 'panelcust',
        ]);

        self::assertIsArray($row);
        $logged = $this->logged[1]['response'];
        self::assertIsArray($logged);

        // The panel login hash and the 2FA recovery hash: kept, masked.
        self::assertSame($row['passwort'], $logged['passwort']);
        self::assertContains($row['passwort'], $this->logged[1]['sensitive']);
        self::assertContains($row['otp_recovery'], $this->logged[1]['sensitive']);
        // The 2FA mail address, the reset-token hash: gone.
        self::assertArrayNotHasKey('otp_data', $logged);
        self::assertArrayNotHasKey('lost_password_hash', $logged);
        self::assertArrayNotHasKey('lost_password_reqtime', $logged);
        // What Sync\AccountLister and Usage\Collector read off this row.
        self::assertSame($row['userid'], $logged['userid']);
        self::assertSame($row['groups'], $logged['groups']);
        self::assertSame($row['default_group'], $logged['default_group']);
    }

    /**
     * The projection map is keyed by METHOD NAME, so it is only as complete as
     * the list of remote functions that answer with a full customer row.
     * `client_get_by_groupid` is one of them and is easy to miss: it does not
     * run a SELECT of its own, it resolves the group to a client_id and then
     * `return $this->client_get($session_id, $client_id)`
     * (remote.d/client.inc.php:690-703), so its response is byte-for-byte the
     * `SELECT * FROM client` record - bank block, tax id, postal address and
     * the free-text `notes` column included.
     *
     * Without an entry of its own it would fall through to the PRIVATE_KEYS
     * scrub, which blanks the enumerated money columns but keeps `notes`,
     * `street`, `company_name` and every column ISPConfig adds in a future
     * release. Same row, same treatment as client_get.
     */
    public function testTheGroupIdClientReadIsProjectedLikeClientGet(): void
    {
        $row = FakeIspconfigServer::clientCustomerRecord(7) + [
            'client_id' => '7',
            'username' => 'panelcust',
            'email' => 'panelcust@example.test',
            'locked' => 'n',
            // Free text: an operator can type anything here, and no value-based
            // masking can catch it. Only the allowlist drops it.
            'notes' => 'Chased about the SEPA mandate on 2026-04-02.',
        ];

        $logged = RestClient::scrubResponse('client_get_by_groupid', $row);
        self::assertIsArray($logged);

        foreach (['bank_account_iban', 'paypal_email', 'vat_id', 'notes', 'street'] as $column) {
            self::assertArrayNotHasKey(
                $column,
                $logged,
                'client.' . $column . ' survived client_get_by_groupid into the log'
            );
        }
        // Still identifiable, exactly as client_get is.
        self::assertSame('7', $logged['client_id']);
        self::assertSame('panelcust', $logged['username']);
        self::assertArrayHasKey(RestClient::SCRUB_NOTICE_KEY, $logged);
    }

    /**
     * The scrub runs TWICE on every real call: once in RestClient::log() and
     * again in Factory's defensive pass, which is deliberate (Factory is the
     * last code the payload passes through). The projection therefore has to be
     * idempotent, and it was not: on the second pass the only key left that the
     * allowlist does not recognise is the module's OWN notice key, so it was
     * dropped and replaced with a freshly counted "1 further column(s)".
     *
     * The effect was that every record-shaped entry in tblmodulelog claimed
     * exactly one column had been dropped, whatever the real number - the
     * accountability the notice exists to provide, quietly reporting a
     * constant. Nothing leaked; the count simply lied.
     */
    public function testTheScrubNoticeSurvivesASecondScrubWithItsCountIntact(): void
    {
        $row = [
            'userid' => '42',
            'username' => 'panelcust',
            'passwort' => 'crypt:seeded',
            // Five columns the sys_user allowlist drops.
            'lost_password_function' => '1',
            'lost_password_hash' => 'reset-token-hash',
            'lost_password_reqtime' => '2026-08-20 11:02:14',
            'otp_data' => 'otp-target@example.test',
            'last_password_change' => '2026-07-01',
        ];

        $once = RestClient::scrubResponse('client_get_by_username', $row);
        self::assertIsArray($once);
        self::assertStringStartsWith(
            '5 further column(s)',
            (string) $once[RestClient::SCRUB_NOTICE_KEY],
            'the first pass must report the true number of dropped columns'
        );

        $twice = RestClient::scrubResponse('client_get_by_username', $once);
        self::assertSame(
            $once,
            $twice,
            'scrubbing an already-scrubbed record must be a no-op'
        );
    }

    public function testAFlagColumnNamedLikeAPasswordIsRedactedAndNeverMasked(): void
    {
        // sys_user.lost_password_function is a 1/0 flag. A substring rule for
        // "password" matches it, and "1" as a replaceVars needle blanks every
        // "1" in the stored entry. It must be dropped, never masked.
        $fake = new FakeIspconfigServer();
        $fake->addClient('panelcust');
        $client = new RestClient($fake, $this->recordingLogger());
        $sessionId = $client->login('remote', 'secret');

        $client->call('client_get_by_username', [
            'session_id' => $sessionId,
            'username' => 'panelcust',
        ]);

        $logged = $this->logged[1]['response'];
        self::assertIsArray($logged);
        self::assertArrayNotHasKey('lost_password_function', $logged);
        self::assertNotContains('1', $this->logged[1]['sensitive']);
        self::assertNotContains('0', $this->logged[1]['sensitive']);
    }

    public function testAnUnenumeratedPasswordColumnIsRedactedRatherThanMasked(): void
    {
        // A column no one listed - a future ISPConfig release, a panel add-on
        // function. It is removed from the log copy; its value is NOT promoted
        // to a masking needle, because an unknown column may hold a flag.
        $envelope = json_encode([
            'code' => 'ok',
            'message' => '',
            'response' => ['smtp_password' => 'hunter2-smtp', 'has_password' => 'y', 'name' => 'box1'],
        ], JSON_THROW_ON_ERROR);
        $client = new RestClient(self::transportReturning($envelope), $this->recordingLogger());

        $result = $client->call('mail_user_get', ['session_id' => 's0000', 'primary_id' => 1]);

        self::assertIsArray($result);
        self::assertSame('hunter2-smtp', $result['smtp_password'], 'the caller must see the panel answer');

        $logged = $this->logged[0]['response'];
        self::assertIsArray($logged);
        self::assertSame(RestClient::REDACTED_PLACEHOLDER, $logged['smtp_password']);
        self::assertSame(RestClient::REDACTED_PLACEHOLDER, $logged['has_password']);
        self::assertSame('box1', $logged['name']);
        self::assertNotContains('hunter2-smtp', $this->logged[0]['sensitive']);
        self::assertNotContains('y', $this->logged[0]['sensitive']);
    }

    public function testKeyMatchingIgnoresCaseAndSeparators(): void
    {
        // The original leak was a spelling mismatch, so matching is exact on a
        // NORMALIZED name: mysqli hands back the column as the schema spells
        // it, but a panel add-on or a future release may not.
        $envelope = json_encode([
            'code' => 'ok',
            'message' => '',
            'response' => [
                'Bank-Account-IBAN' => 'DE00FAKEIBAN00000000',
                'PASSWORT' => 'crypt:$6$panelhash',
                'domain' => 'one.example',
            ],
        ], JSON_THROW_ON_ERROR);
        $client = new RestClient(self::transportReturning($envelope), $this->recordingLogger());

        $client->call('sites_web_domain_get', ['session_id' => 's0000', 'primary_id' => 1]);

        $logged = $this->logged[0]['response'];
        self::assertIsArray($logged);
        self::assertSame(RestClient::REDACTED_PLACEHOLDER, $logged['Bank-Account-IBAN']);
        self::assertContains('crypt:$6$panelhash', $this->logged[0]['sensitive']);
        self::assertSame('one.example', $logged['domain']);
    }

    public function testTheRequestStaysReadableExceptForColumnsTheModuleNeverAuthors(): void
    {
        // Asymmetry on purpose. A REQUEST is written by this module out of
        // WHMCS's own record, and reading it back is the whole point of the
        // module log, so it reaches the logger intact and its secrets are
        // covered by replaceVars (spec 2.2). A RESPONSE is the panel's copy of
        // every customer it hosts, which is a different question.
        $client = new RestClient(
            self::transportReturning('{"code":"ok","message":"","response":7}'),
            $this->recordingLogger()
        );

        $client->call('client_add', [
            'session_id' => 's0000',
            'reseller_id' => 0,
            'params' => [
                'username' => 'jane',
                'contact_name' => 'Jane Doe',
                'street' => 'Main Street 1',
                'password' => 'Cl13nt-Pw!',
                'vat_id' => 'DE-FAKE-VATID-1',
            ],
        ]);

        $logged = $this->logged[0]['request'];
        self::assertSame('jane', $logged['params']['username']);
        self::assertSame('Jane Doe', $logged['params']['contact_name']);
        self::assertSame('Main Street 1', $logged['params']['street']);
        // Intact, and masked by value.
        self::assertSame('Cl13nt-Pw!', $logged['params']['password']);
        self::assertContains('Cl13nt-Pw!', $this->logged[0]['sensitive']);
        // A column this module never authors: dropped even on the way out.
        self::assertSame(RestClient::REDACTED_PLACEHOLDER, $logged['params']['vat_id']);
    }
}
