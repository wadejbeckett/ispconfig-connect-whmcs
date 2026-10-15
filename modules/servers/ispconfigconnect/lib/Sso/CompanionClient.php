<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Sso;

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\ApiProtocolException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\PermissionDeniedException;
use WHMCS\Module\Server\Ispconfigconnect\Api\ServerConfig;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Sso\Exception\CompanionGrantMissingException;
use WHMCS\Module\Server\Ispconfigconnect\Sso\Exception\CompanionNotInstalledException;
use WHMCS\Module\Server\Ispconfigconnect\Sso\Exception\CompanionResponseException;
use WHMCS\Module\Server\Ispconfigconnect\Sso\Exception\SsoException;

/**
 * WHMCS-side client for the OPTIONAL panel-side SSO project.
 *
 * That project - repository `ispconfig-sso`, same author, GPLv3 - is a
 * STANDALONE ISPConfig add-on: it is not shipped by this module, is not part of
 * this repository, and is not specific to WHMCS (any billing system can drive
 * the same remote function). This module is one consumer of it, and treats it
 * strictly as an external, optional capability of the panel it is pointed at.
 *
 * ISPConfig core has no one-time-login API: `client_login_get` only validates
 * credentials and `login(..., true)` mints API sessions, not panel sessions
 * (verified across the 3.3.1p1 tree). The SSO project adds exactly one remote
 * function through the documented remote.d auto-load point
 * (interface/lib/classes/remoting_handler_base.inc.php:47 globs
 * interface/web/*&#47;lib/classes/remote.d/*.inc.php), which mints a short-TTL
 * single-use token; a consumer page in the same panel module burns the token
 * and establishes the panel session.
 *
 * ITS ABSENCE IS THE DEFAULT, NOT AN ERROR: a stock panel does not have it, so
 * detection is one cheap call (get_function_list, already granted), and every
 * accessor has a *OrNull sibling so an entry point can hide the SSO button
 * instead of showing a dead one. Infrastructure failures (unreachable panel,
 * lockout, disabled API) always propagate - "cannot reach the panel" must never
 * be reported to an operator as "SSO is not installed".
 *
 * All API traffic goes through SessionManager, which owns login, session
 * reuse, the single expired-session retry and the lockout circuit.
 */
final class CompanionClient
{
    /** The single remote function the ispconfig-sso project registers. */
    public const REMOTE_FUNCTION = 'connect_sso_token_create';

    /** The ispconfig-sso project is present and its function is registered. */
    public const STATUS_INSTALLED = 'installed';

    /** The panel answered, and the project is not there. */
    public const STATUS_ABSENT = 'absent';

    /** The probe was refused, so presence cannot be determined either way. */
    public const STATUS_UNKNOWN = 'unknown';

    /** Consumer page path used when the panel does not report its own. */
    public const DEFAULT_ENDPOINT_PATH = '/connectsso/';

    /** Query-string parameter the consumer page reads the token from. */
    public const TOKEN_PARAM = 'token';

    /**
     * URL-safe charset only. The token goes into a query string verbatim, so
     * anything that could terminate or extend that query ('&', '#', '?', '/',
     * whitespace, '%') is refused rather than escaped - a panel that wants to
     * send those is a panel we do not understand. 32 chars is the floor for a
     * credential of this kind; the ispconfig-sso project's is 64 chars
     * (random_bytes(32) hex).
     */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{32,256}$/';

    /**
     * A single leading slash, then only unreserved path characters. This is
     * the open-redirect guard: '//evil.example/' (protocol-relative),
     * 'https://evil.example/' (absolute), 'connectsso/' (relative to whatever
     * the browser was on) and anything carrying '?', '#' or a backslash all
     * fail here. '..' segments are rejected separately below.
     */
    private const PATH_PATTERN = '/^\/(?!\/)[A-Za-z0-9._~\/-]{0,120}$/';

    /** Sanity ceiling on a reported TTL; the SSO project's own is <= 60 s. */
    private const MAX_REPORTED_TTL = 300;

    /** Base URL of the panel UI, WITHOUT a trailing slash. */
    private readonly string $panelBaseUrl;

    /**
     * @param string|null $panelBaseUrl operator override for the panel's
     *        public base URL (product option "Panel URL Override"). Null uses
     *        the API host/port from the WHMCS server entry, which is right
     *        whenever the panel is reachable at the same address.
     * @throws \InvalidArgumentException when the override is not an absolute
     *         http(s) URL with a host - a bad override would send customers
     *         somewhere unintended
     */
    public function __construct(
        private readonly SessionManager $session,
        ServerConfig $config,
        ?string $panelBaseUrl = null,
    ) {
        $this->panelBaseUrl = $panelBaseUrl === null || trim($panelBaseUrl) === ''
            ? rtrim($config->baseUrl(), '/')
            : self::normalizeBaseUrl($panelBaseUrl);
    }

    /**
     * Is the ispconfig-sso project deployed on this panel?
     *
     * One call to get_function_list, which returns the handler's FULL
     * registered method map - including every method the module remote.d
     * classes contributed (remoting_handler_base.inc.php builds the map before
     * handing array_keys() to the remoting instance). That makes it a true
     * presence probe and not a grant probe.
     *
     * Returns false when the project is absent AND when the probe could not
     * see the function list at all. Callers that report the reason to a human
     * must use status() instead: telling an operator "not installed" when the
     * truth is "we were not allowed to look" sends them to install software
     * they may already have. The ispconfig-sso project's own integration guide
     * asks consumers not to conflate the two, and this module is one of its
     * consumers.
     *
     * Transport, protocol and lockout failures propagate.
     */
    public function isInstalled(): bool
    {
        return $this->status() === self::STATUS_INSTALLED;
    }

    /**
     * The three honestly-distinguishable answers to "can this panel do SSO?".
     *
     * STATUS_UNKNOWN is not a failure: the panel may well have the project
     * installed. It means the remote user lacks the get_function_list grant,
     * so the probe cannot see the registered method map either way.
     */
    public function status(): string
    {
        try {
            $functions = $this->session->call('get_function_list', []);
        } catch (PermissionDeniedException) {
            return self::STATUS_UNKNOWN;
        }

        return is_array($functions) && in_array(self::REMOTE_FUNCTION, $functions, true)
            ? self::STATUS_INSTALLED
            : self::STATUS_ABSENT;
    }

    /**
     * Mint a one-time login ticket for an ISPConfig client id.
     *
     * @param int         $ispconfigClientId the panel-side client.client_id
     * @param string|null $bindIp the END USER's address as WHMCS sees it, when
     *        the token should only be redeemable from there. This is NOT the
     *        WHMCS server's address: the browser redeems the token, WHMCS only
     *        mints it. Null leaves the token unbound (the ispconfig-sso project
     *        documents the trade-off: bind only when the panel sees the same
     *        address WHMCS does - a CDN, proxy or IPv4/IPv6 asymmetry in front
     *        of either side breaks binding).
     *
     * @throws \InvalidArgumentException          on a non-positive client id or a non-IP bind value
     * @throws CompanionNotInstalledException     panel does not have the SSO project
     * @throws CompanionGrantMissingException     project present, grant missing
     * @throws CompanionResponseException         payload not safe to redirect with
     */
    public function ticket(int $ispconfigClientId, ?string $bindIp = null): SsoTicket
    {
        if ($ispconfigClientId <= 0) {
            throw new \InvalidArgumentException(
                'An ISPConfig client id is required to mint an SSO token; got ' . $ispconfigClientId
            );
        }
        $bind = $this->normalizeBindIp($bindIp);

        try {
            $response = $this->session->call(self::REMOTE_FUNCTION, [
                'client_id' => $ispconfigClientId,
                'bind_ip' => $bind,
            ]);
        } catch (PermissionDeniedException $denied) {
            throw new CompanionGrantMissingException(
                'The ISPConfig remote user may not call ' . self::REMOTE_FUNCTION
                . '. Grant it in System > Remote Users > (user) > Functions.',
                0,
                $denied
            );
        } catch (ApiProtocolException $protocol) {
            if ($protocol->apiCode === 'invalid_method') {
                throw new CompanionNotInstalledException(
                    'This ISPConfig panel does not provide ' . self::REMOTE_FUNCTION
                    . '. Install the separate ISPConfig SSO project (ispconfig-sso)'
                    . ' on the panel to enable single sign-on.',
                    0,
                    $protocol
                );
            }

            throw $protocol;
        }

        return $this->ticketFrom($response);
    }

    /**
     * ticket(), but null whenever SSO is simply not available on this panel
     * (absent SSO project, missing grant, payload we refuse to redirect with).
     * Everything else - transport failures, lockout, a panel that refuses this
     * particular client - still throws, because those need to be seen.
     */
    public function ticketOrNull(int $ispconfigClientId, ?string $bindIp = null): ?SsoTicket
    {
        try {
            return $this->ticket($ispconfigClientId, $bindIp);
        } catch (SsoException) {
            return null;
        }
    }

    /** @throws SsoException|\InvalidArgumentException see ticket() */
    public function loginUrl(int $ispconfigClientId, ?string $bindIp = null): string
    {
        return $this->ticket($ispconfigClientId, $bindIp)->url;
    }

    /** Null when SSO is not available on this panel; see ticketOrNull(). */
    public function loginUrlOrNull(int $ispconfigClientId, ?string $bindIp = null): ?string
    {
        return $this->ticketOrNull($ispconfigClientId, $bindIp)?->url;
    }

    /** The panel base URL this client builds redirects against, no trailing slash. */
    public function panelBaseUrl(): string
    {
        return $this->panelBaseUrl;
    }

    private function ticketFrom(mixed $response): SsoTicket
    {
        if (!is_array($response)) {
            throw new CompanionResponseException(
                self::REMOTE_FUNCTION . ' did not return an object'
            );
        }

        $token = $response['token'] ?? null;
        if (!is_string($token) || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            // Never quote the value back: a rejected payload is still
            // attacker-influenced, and this message reaches logs and admin UI.
            throw new CompanionResponseException(
                self::REMOTE_FUNCTION . ' returned no usable one-time token'
            );
        }

        $path = $response['path'] ?? self::DEFAULT_ENDPOINT_PATH;
        if ($path === '') {
            $path = self::DEFAULT_ENDPOINT_PATH;
        }
        if (
            !is_string($path)
            || preg_match(self::PATH_PATTERN, $path) !== 1
            || str_contains($path, '..')
        ) {
            throw new CompanionResponseException(
                self::REMOTE_FUNCTION . ' returned an endpoint path that is not a safe local path'
            );
        }

        $username = $response['username'] ?? null;

        return new SsoTicket(
            $token,
            $this->panelBaseUrl . $path
                . (str_contains($path, '?') ? '&' : '?')
                . self::TOKEN_PARAM . '=' . rawurlencode($token),
            self::expiryFrom($response['expires_in'] ?? null),
            is_string($username) && $username !== '' ? $username : null
        );
    }

    private static function expiryFrom(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return 0; // unknown, not "expired" - the panel is the authority
        }
        $seconds = (int) $value;

        return $seconds > 0 && $seconds <= self::MAX_REPORTED_TTL ? $seconds : 0;
    }

    /**
     * '' means "no IP binding". Anything else must be a literal IP address:
     * the panel stores it and compares it to the redeeming request's
     * REMOTE_ADDR, so a hostname would either never match or (once resolved)
     * bind to the wrong address.
     */
    private function normalizeBindIp(?string $bindIp): string
    {
        $bind = trim($bindIp ?? '');
        if ($bind === '') {
            return '';
        }
        if (filter_var($bind, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException(
                'The SSO bind address must be a literal IP address, not a hostname'
            );
        }

        return $bind;
    }

    private static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || (string) $parts['host'] === ''
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException(
                'The panel URL override must be an absolute http(s) URL, e.g. https://cp.example.net'
            );
        }

        return rtrim($url, '/');
    }
}
