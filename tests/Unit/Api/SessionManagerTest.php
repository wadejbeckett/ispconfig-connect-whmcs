<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Api;

use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LockoutException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LoginFailedException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\SessionExpiredException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\TransportException;
use WHMCS\Module\Server\Ispconfigconnect\Api\HttpTransportInterface;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;

/**
 * SessionManager (spec 2.3) exercised through the REAL RestClient over the
 * behavioral fake of remote/json.php - the full envelope path, no mocks.
 */
#[CoversClass(SessionManager::class)]
final class SessionManagerTest extends TestCase
{
    private const SENTINEL_SESSION_EXPIRED = 'The Session is expired or does not exist.';
    private const SENTINEL_LOGIN_LOCKOUT = 'The login failure limit has been reached.';
    private const SENTINEL_SESSION_LOCKOUT = 'The Session failure limit has been reached.';
    private const SENTINEL_PERMISSION_DENIED = 'You do not have the permissions to access this function.';

    /**
     * Manager whose clock tracks the fake server's, so `$fake->clock += n`
     * ages the session on both sides at once.
     */
    private function managerSharingClock(FakeIspconfigServer $fake, string $password = 'secret'): SessionManager
    {
        return new SessionManager(
            new RestClient($fake),
            'remote',
            $password,
            static fn (): int => $fake->clock,
        );
    }

    /** @return list<string> method names in call order */
    private static function methodSequence(FakeIspconfigServer $fake): array
    {
        return array_map(static fn (array $entry): string => $entry['method'], $fake->requestLog);
    }

    private static function sessionIdOfRequest(FakeIspconfigServer $fake, int $index): string
    {
        $params = $fake->requestLog[$index]['params'];
        self::assertArrayHasKey('session_id', $params);
        self::assertIsString($params['session_id']);

        return $params['session_id'];
    }

    // ---- lazy login & session reuse ----------------------------------------

    public function testConstructionMakesNoTransportRequests(): void
    {
        $fake = new FakeIspconfigServer();
        $manager = $this->managerSharingClock($fake);

        self::assertInstanceOf(SessionManager::class, $manager);
        self::assertSame([], $fake->requestLog);
    }

    public function testFirstCallLogsInLazilyThenInvokesMethod(): void
    {
        $fake = new FakeIspconfigServer();
        $fake->jobqueueCount = 7;
        $manager = $this->managerSharingClock($fake);

        $result = $manager->call('monitor_jobqueue_count', ['server_id' => 0]);

        // monitor.inc.php returns the mysqli column value - a STRING; the
        // manager passes responses through untyped.
        self::assertSame('7', $result);
        self::assertSame(['login', 'monitor_jobqueue_count'], self::methodSequence($fake));
    }

    public function testSessionIsReusedAcrossCalls(): void
    {
        $fake = new FakeIspconfigServer();
        $manager = $this->managerSharingClock($fake);

        $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
        $manager->call('server_get_all', []);
        $manager->call('client_templates_get_all', []);

        $sequence = self::methodSequence($fake);
        self::assertSame(1, count(array_keys($sequence, 'login', true)), 'exactly one login expected');
        self::assertSame(
            ['login', 'monitor_jobqueue_count', 'server_get_all', 'client_templates_get_all'],
            $sequence
        );
        // Every non-login request carried the SAME session id.
        self::assertSame(
            self::sessionIdOfRequest($fake, 1),
            self::sessionIdOfRequest($fake, 2)
        );
        self::assertSame(
            self::sessionIdOfRequest($fake, 1),
            self::sessionIdOfRequest($fake, 3)
        );
    }

    // ---- session_id injection ----------------------------------------------

    public function testCallerSuppliedSessionIdIsOverridden(): void
    {
        $fake = new FakeIspconfigServer();
        $manager = $this->managerSharingClock($fake);

        $manager->call('monitor_jobqueue_count', [
            'session_id' => 'sforged00000000000000000000000000000000000',
            'server_id' => 0,
        ]);

        $sent = self::sessionIdOfRequest($fake, 1);
        self::assertNotSame('sforged00000000000000000000000000000000000', $sent);
        self::assertMatchesRegularExpression('/^[a-z][0-9a-f]{40}$/', $sent);
        // The rest of the caller's params survive.
        self::assertSame(0, $fake->requestLog[1]['params']['server_id']);
    }

    // ---- proactive re-login (25-minute rule, spec 1.4) ---------------------

    public function testNoReloginWhileSessionYoungerThan1500Seconds(): void
    {
        $fake = new FakeIspconfigServer();
        $manager = $this->managerSharingClock($fake);

        $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
        $fake->clock += 1400;
        $manager->call('monitor_jobqueue_count', ['server_id' => 0]);

        self::assertSame(
            ['login', 'monitor_jobqueue_count', 'monitor_jobqueue_count'],
            self::methodSequence($fake)
        );
    }

    public function testProactiveReloginOnceSessionOlderThan1500Seconds(): void
    {
        $fake = new FakeIspconfigServer();
        $manager = $this->managerSharingClock($fake);

        $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
        // 1600 s: past the 1500 s proactive threshold but INSIDE the server's
        // 1800 s TTL - the old session would still work, so a second login
        // proves the re-login is proactive, not error-driven.
        $fake->clock += 1600;
        $manager->call('monitor_jobqueue_count', ['server_id' => 0]);

        self::assertSame(
            ['login', 'monitor_jobqueue_count', 'login', 'monitor_jobqueue_count'],
            self::methodSequence($fake)
        );
        self::assertNotSame(
            self::sessionIdOfRequest($fake, 1),
            self::sessionIdOfRequest($fake, 3)
        );
    }

    // ---- expired-session retry ---------------------------------------------

    public function testServerSideExpiryTriggersExactlyOneReloginAndSucceeds(): void
    {
        $fake = new FakeIspconfigServer();
        $fake->jobqueueCount = 3;
        // Frozen manager clock: the manager believes the session is fresh
        // while the server has expired it (clock-skew scenario) - forces the
        // SessionExpiredException path instead of the proactive one.
        $frozenNow = $fake->clock;
        $manager = new SessionManager(new RestClient($fake), 'remote', 'secret', static fn (): int => $frozenNow);

        $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
        $fake->clock += 1801; // past the 1800 s TTL (remoting.inc.php)

        $result = $manager->call('monitor_jobqueue_count', ['server_id' => 0]);

        // String per the wire contract (mysqli column value, monitor.inc.php).
        self::assertSame('3', $result);
        self::assertSame(
            ['login', 'monitor_jobqueue_count', 'monitor_jobqueue_count', 'login', 'monitor_jobqueue_count'],
            self::methodSequence($fake)
        );
        // The retry used a FRESH session id.
        self::assertNotSame(
            self::sessionIdOfRequest($fake, 2),
            self::sessionIdOfRequest($fake, 4)
        );
    }

    public function testSessionExpiredIsRetriedOnlyOnce(): void
    {
        $fake = new FakeIspconfigServer();
        $manager = $this->managerSharingClock($fake);
        // Both the first attempt AND the post-relogin retry hit an expired
        // session -> the second SessionExpiredException must propagate.
        $fake->failNextWith('monitor_jobqueue_count', self::SENTINEL_SESSION_EXPIRED);
        $fake->failNextWith('monitor_jobqueue_count', self::SENTINEL_SESSION_EXPIRED);

        try {
            $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
            self::fail('Expected SessionExpiredException');
        } catch (SessionExpiredException) {
        }

        self::assertSame(
            ['login', 'monitor_jobqueue_count', 'login', 'monitor_jobqueue_count'],
            self::methodSequence($fake)
        );
    }

    // ---- no retry on login failure -----------------------------------------

    public function testLoginFailureIsNotRetried(): void
    {
        $fake = new FakeIspconfigServer();
        $manager = $this->managerSharingClock($fake, 'wrong-password');

        try {
            $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
            self::fail('Expected LoginFailedException');
        } catch (LoginFailedException) {
        }

        // Exactly one transport request: the failed login. Retrying would
        // feed the panel's 10-failures-per-5-minutes IP lockout.
        self::assertSame(['login'], self::methodSequence($fake));
    }

    // ---- lockout circuit ----------------------------------------------------

    public function testLockoutAtLoginOpensPermanentCircuit(): void
    {
        $fake = new FakeIspconfigServer();
        $manager = $this->managerSharingClock($fake);
        $fake->failNextWith('login', self::SENTINEL_LOGIN_LOCKOUT);

        try {
            $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
            self::fail('Expected LockoutException');
        } catch (LockoutException $first) {
        }
        self::assertCount(1, $fake->requestLog);

        try {
            $manager->call('server_get_all', []);
            self::fail('Expected stored LockoutException on second call');
        } catch (LockoutException $second) {
            self::assertSame($first, $second, 'the STORED exception must be rethrown');
        }
        // Zero new transport requests: the circuit is open.
        self::assertCount(1, $fake->requestLog);
    }

    public function testLockoutDuringSessionCallOpensCircuitAndCloseStaysSilent(): void
    {
        $fake = new FakeIspconfigServer();
        $manager = $this->managerSharingClock($fake);

        $manager->call('server_get_all', []); // login + call succeed
        $fake->failNextWith('monitor_jobqueue_count', self::SENTINEL_SESSION_LOCKOUT);

        try {
            $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
            self::fail('Expected LockoutException');
        } catch (LockoutException) {
        }
        $requestsAtLockout = count($fake->requestLog);

        try {
            $manager->call('server_get_all', []);
            self::fail('Expected stored LockoutException');
        } catch (LockoutException) {
        }
        // Even close() must not touch the wire after lockout - a logout with
        // the dead session would extend the lockout window.
        $manager->close();

        self::assertCount($requestsAtLockout, $fake->requestLog);
    }

    // ---- transport-failure circuit (fail fast, per instance) ----------------

    public function testTransportFailureLatchesTheCircuitOnTheSameManager(): void
    {
        // A transport failure cannot resolve mid-batch on the same connection.
        // The NEXT call() on the SAME manager must fail fast with the stored
        // exception - not re-attempt (burning another CURLOPT_TIMEOUT) and not
        // silently continue - exactly as a lockout does.
        $fake = new FakeIspconfigServer();
        $transport = new class implements HttpTransportInterface {
            public int $attempts = 0;

            public function post(string $method, string $jsonBody): string
            {
                $this->attempts++;

                throw new TransportException('connect timeout after 10s');
            }
        };
        $manager = new SessionManager(
            new RestClient($transport),
            'remote',
            'secret',
            static fn (): int => $fake->clock,
        );

        try {
            $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
            self::fail('Expected TransportException');
        } catch (TransportException $first) {
            $attemptsAfterFirst = $transport->attempts;

            try {
                $manager->call('server_get_all', []);
                self::fail('Expected the stored TransportException on the second call');
            } catch (TransportException $second) {
                self::assertSame($first, $second, 'the stored exception must be rethrown');
            }
            // The second call touched the wire zero times.
            self::assertSame($attemptsAfterFirst, $transport->attempts);
        }
    }

    public function testAFreshManagerDoesNotInheritAnotherInstancesCircuit(): void
    {
        // The circuit is per INSTANCE: each per-operation entry point builds a
        // new SessionManager, which must start clean even right after a sibling
        // tripped its own circuit.
        $fake = new FakeIspconfigServer();
        $failing = new class implements HttpTransportInterface {
            public function post(string $method, string $jsonBody): string
            {
                throw new TransportException('connect timeout after 10s');
            }
        };

        $doomed = new SessionManager(
            new RestClient($failing),
            'remote',
            'secret',
            static fn (): int => $fake->clock,
        );
        try {
            $doomed->call('server_get_all', []);
            self::fail('Expected TransportException');
        } catch (TransportException) {
        }

        $healthy = new SessionManager(
            new RestClient($fake),
            'remote',
            'secret',
            static fn (): int => $fake->clock,
        );
        $healthy->call('server_get_all', []);

        // The fresh manager logged in and ran the call normally.
        self::assertSame(['login', 'server_get_all'], self::methodSequence($fake));
    }

    // ---- close() ------------------------------------------------------------

    public function testCloseLogsOutTheActiveSession(): void
    {
        $fake = new FakeIspconfigServer();
        $manager = $this->managerSharingClock($fake);

        $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
        $sessionId = self::sessionIdOfRequest($fake, 1);

        $manager->close();

        self::assertSame(['login', 'monitor_jobqueue_count', 'logout'], self::methodSequence($fake));
        self::assertSame($sessionId, self::sessionIdOfRequest($fake, 2));
        self::assertSame(0, $fake->activeSessionCount());
    }

    public function testCloseBeforeAnyCallMakesNoRequests(): void
    {
        $fake = new FakeIspconfigServer();
        $manager = $this->managerSharingClock($fake);

        $manager->close();
        $manager->close();

        self::assertSame([], $fake->requestLog);
    }

    public function testCloseTwiceLogsOutOnlyOnce(): void
    {
        $fake = new FakeIspconfigServer();
        $manager = $this->managerSharingClock($fake);

        $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
        $manager->close();
        $manager->close();

        self::assertSame(['login', 'monitor_jobqueue_count', 'logout'], self::methodSequence($fake));
    }

    public function testCallAfterCloseLogsInAgain(): void
    {
        $fake = new FakeIspconfigServer();
        $manager = $this->managerSharingClock($fake);

        $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
        $manager->close();
        $manager->call('monitor_jobqueue_count', ['server_id' => 0]);

        self::assertSame(
            ['login', 'monitor_jobqueue_count', 'logout', 'login', 'monitor_jobqueue_count'],
            self::methodSequence($fake)
        );
    }

    public function testCloseSwallowsTransportFailureOnLogout(): void
    {
        $fake = new FakeIspconfigServer();
        $transport = new class ($fake) implements HttpTransportInterface {
            public int $logoutAttempts = 0;

            public function __construct(private readonly FakeIspconfigServer $fake)
            {
            }

            public function post(string $method, string $jsonBody): string
            {
                if (str_starts_with($method, 'logout')) {
                    $this->logoutAttempts++;

                    throw new TransportException('connect timeout after 10s');
                }

                return $this->fake->post($method, $jsonBody);
            }
        };
        $manager = new SessionManager(
            new RestClient($transport),
            'remote',
            'secret',
            static fn (): int => $fake->clock,
        );

        $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
        $manager->close(); // must not throw
        $manager->close(); // state cleared: no second logout attempt

        self::assertSame(1, $transport->logoutAttempts);
    }

    public function testCloseSwallowsApiLevelLogoutFailures(): void
    {
        // close() runs in entry-file finally blocks: a thrown exception there
        // would replace the operation's real outcome (a successful create
        // would report as failed with no ids persisted), so every API-level
        // logout failure is swallowed - the work is already done.
        $fake = new FakeIspconfigServer();
        $manager = $this->managerSharingClock($fake);

        $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
        $fake->failNextWith('logout', self::SENTINEL_PERMISSION_DENIED);

        $manager->close(); // must not throw

        // State cleared despite the failure: a later call re-logins cleanly.
        $manager->call('monitor_jobqueue_count', ['server_id' => 0]);
        self::assertSame(
            ['login', 'monitor_jobqueue_count', 'logout', 'login', 'monitor_jobqueue_count'],
            self::methodSequence($fake)
        );
    }
}
