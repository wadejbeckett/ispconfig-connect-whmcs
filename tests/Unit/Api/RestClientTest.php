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
}
