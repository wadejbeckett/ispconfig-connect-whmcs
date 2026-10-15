<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api;

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\IspconfigException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LockoutException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LoginFailedException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\SessionExpiredException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\TransportException;

/**
 * Session lifecycle around RestClient (spec 2.3): one lazy login per batch,
 * session reuse, proactive re-login, a single expired-session retry, and a
 * permanent lockout circuit.
 *
 * The rules mirror ISPConfig 3.3.1p1 remoting.inc.php (spec 1.4): the session
 * TTL is 1800 s FIXED AT LOGIN and never extended by use, failed session
 * lookups feed the same 10-failures-per-5-minutes IP counter as failed
 * logins, and a successful login clears that counter.  Never logs, never
 * echoes.
 */
final class SessionManager
{
    /**
     * Re-login once the session is older than 25 minutes - a 300 s safety
     * margin under the server's fixed 1800 s TTL (spec 1.4).
     */
    private const PROACTIVE_RELOGIN_AGE_SECONDS = 1500;

    /**
     * How many TransportExceptions IN A ROW open the circuit. Two, not one: a
     * usage/metrics sweep shares one manager across every service on the
     * server, and one proxy 502, one HTTP 500 on one tenant's read or one slow
     * response past CURLOPT_TIMEOUT must cost that ONE service its import, not
     * every service after it (INSTALL: "one unreadable service never costs the
     * others their import"). A panel that is really down fails the very next
     * call too, so the fail-fast saving is kept at the price of one extra
     * timeout.
     */
    private const TRANSPORT_FAILURES_TO_LATCH = 2;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    private ?string $sessionId = null;
    private int $sessionIssuedAt = 0;

    /**
     * Once set, every further call() rethrows this without touching the wire.
     * It latches the failures that cannot turn valid again mid-batch on the
     * same manager:
     *   - LockoutException, at once: any further request - even a dead-session
     *     logout - extends the panel's 5-minute lockout window;
     *   - LoginFailedException, at once: a wrong password or an IP-allowlist
     *     rejection cannot become valid mid-batch, and re-attempting each call
     *     spends the panel's 10-failures-per-5-minutes budget toward a real
     *     lockout;
     *   - TransportException, only after TRANSPORT_FAILURES_TO_LATCH
     *     consecutive ones: a panel that stays unreachable burns one full
     *     CURLOPT_TIMEOUT per call(), but a single failure may be transient.
     *
     * This is INSTANCE state, never static: every per-operation entry point
     * builds a fresh SessionManager, which therefore always starts clean.
     */
    private ?IspconfigException $circuit = null;

    /**
     * TransportExceptions since the panel last ANSWERED - a successful call, a
     * successful login, or any other IspconfigException, all of which prove
     * the wire works. Only an
     * unbroken run of transport failures opens the circuit. Instance state,
     * for the same reason as $circuit.
     */
    private int $consecutiveTransportFailures = 0;

    /** @param null|\Closure(): int $clock unix-time source, injectable for TTL tests */
    public function __construct(
        private readonly RestClient $client,
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param array<string, mixed> $params WITHOUT session_id - the manager
     *        owns it, and any caller-supplied session_id is overridden
     * @return mixed the envelope's "response" member
     */
    public function call(string $method, array $params): mixed
    {
        if ($this->circuit !== null) {
            throw $this->circuit;
        }

        try {
            try {
                $result = $this->client->call($method, $this->withSession($params));
            } catch (SessionExpiredException) {
                // Clock skew or server restart: retry EXACTLY once with a
                // fresh login (a second expiry propagates - repeated retries
                // with dead sessions feed the IP lockout, spec 1.4).
                $this->sessionId = null;

                $result = $this->client->call($method, $this->withSession($params));
            }
        } catch (LockoutException | LoginFailedException $fatal) {
            // Latch for the rest of this instance's life: neither becomes valid
            // again on the same manager, and re-attempting only extends a
            // lockout or spends the failure budget toward one (spec 1.4).
            // A caller-supplied SessionExpired is handled above, not here, so a
            // genuine clock-skew retry is never latched.
            $this->circuit = $fatal;
            $this->sessionId = null; // dead/unknown session: close() must not try to log it out

            throw $fatal;
        } catch (TransportException $failure) {
            $this->consecutiveTransportFailures++;
            if ($this->consecutiveTransportFailures >= self::TRANSPORT_FAILURES_TO_LATCH) {
                $this->circuit = $failure;
            }
            // Forgotten on EVERY transport failure, latched or not: whether the
            // link works is now unknown, and close() runs in entry-file finally
            // blocks, where a logout over a dead link would add a second full
            // CURLOPT_TIMEOUT to the one the operation already spent. The
            // session itself expires server-side (TTL fixed at login) and is
            // garbage-collected at the next login; the next call() here simply
            // logs in again.
            $this->sessionId = null;

            throw $failure;
        } catch (IspconfigException $answered) {
            // An API-level fault (permission denied, a record not found, a
            // malformed body) is still an ANSWER: the wire works, so it breaks
            // a run of transport failures exactly as a success does.
            $this->consecutiveTransportFailures = 0;

            throw $answered;
        }

        $this->consecutiveTransportFailures = 0;

        return $result;
    }

    /**
     * Best-effort logout.  Swallows every API-level failure: the batch's work
     * is already done, the server garbage-collects expired sessions at each
     * login anyway, and close() runs in entry-file finally blocks where a
     * thrown exception would REPLACE the operation's real outcome.  Safe to
     * call twice or before any call.
     */
    public function close(): void
    {
        $sessionId = $this->sessionId;
        $this->sessionId = null; // cleared first: a second close() is a no-op even if logout throws

        if ($sessionId === null) {
            return;
        }

        try {
            $this->client->logout($sessionId);
        } catch (IspconfigException) {
            // Best-effort by spec 2.3 - the batch's work is already done, and
            // close() runs in entry-file finally blocks: an exception here
            // would REPLACE the operation's real outcome (PHP finally
            // semantics), turning a successful create into a reported failure.
            // The RestClient logger has already recorded the failed logout.
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function withSession(array $params): array
    {
        $params['session_id'] = $this->ensureSession();

        return $params;
    }

    private function ensureSession(): string
    {
        $now = ($this->clock)();
        if ($this->sessionId === null
            || $now - $this->sessionIssuedAt > self::PROACTIVE_RELOGIN_AGE_SECONDS
        ) {
            $this->sessionId = $this->client->login($this->username, $this->password);
            $this->sessionIssuedAt = $now;
            // A login that succeeds is an answer too: the panel is up, so a
            // transport failure before it and one after it are not "in a row".
            // Without this, two unreadable tenants next to each other in a
            // sweep latched the circuit although the re-login between them
            // proved the wire works, and every later tenant went unread.
            $this->consecutiveTransportFailures = 0;
        }

        return $this->sessionId;
    }
}
