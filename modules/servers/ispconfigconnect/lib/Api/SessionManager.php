<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api;

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\IspconfigException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LockoutException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\SessionExpiredException;

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

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    private ?string $sessionId = null;
    private int $sessionIssuedAt = 0;

    /**
     * Once set, every further call() rethrows this without touching the
     * wire: any request after a lockout - even a logout, whose dead-session
     * lookup counts as a failure - extends the 5-minute lockout window.
     */
    private ?LockoutException $lockout = null;

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
        if ($this->lockout !== null) {
            throw $this->lockout;
        }

        try {
            try {
                return $this->client->call($method, $this->withSession($params));
            } catch (SessionExpiredException) {
                // Clock skew or server restart: retry EXACTLY once with a
                // fresh login (a second expiry propagates - repeated retries
                // with dead sessions feed the IP lockout, spec 1.4).
                $this->sessionId = null;

                return $this->client->call($method, $this->withSession($params));
            }
        } catch (LockoutException $lockout) {
            $this->lockout = $lockout;
            $this->sessionId = null; // dead session: close() must not try to log it out

            throw $lockout;
        }
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
        }

        return $this->sessionId;
    }
}
