<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api;

/**
 * Connection settings for one ISPConfig panel, derived from the WHMCS server
 * form by Support\Params:
 *   hostname -> host, port (default 8080), Secure checkbox -> secure,
 *   username/password -> the least-privilege REMOTE user (never the panel
 *   admin), Access Hash -> optional CA bundle (path to, or literal PEM).
 *
 * TLS verification is ON unless the operator explicitly disables it.
 */
final class ServerConfig
{
    /**
     * The validated, connection-ready host: a bare DNS host name, a plain
     * IPv4, or a BRACKETED IPv6 literal. It is concatenated straight into the
     * URL that carries the remote-API password, so it is never anything else.
     */
    public readonly string $host;

    public function __construct(
        string $host,
        public readonly string $username,
        #[\SensitiveParameter]
        public readonly string $password,
        public readonly int $port = 8080,
        public readonly bool $secure = true,
        public readonly bool $verifyTls = true,
        public readonly ?string $caBundle = null,
        public readonly int $timeoutSeconds = 30,
    ) {
        $this->host = self::validateHost($host);
    }

    /**
     * The WHMCS Hostname / IP field is concatenated straight into the endpoint
     * URL that POSTs the remote-API password (and, via panelUrl(), the SSO
     * redirect target). A trailing slash, a scheme, an embedded port or
     * userinfo silently RETARGETS that POST: "panel.example.com/" builds
     * "https://panel.example.com/:8080/remote/json.php", so curl connects to
     * 443 - the web tier - and sends the credentials there, TLS still valid.
     *
     * So a malformed value is refused loudly, with a message naming the problem
     * (TestConnection catches it and shows it), never silently "cleaned". The
     * one transform made is bracketing a bare IPv6 literal, which the URL
     * syntax requires. Plain host names and plain IPv4 addresses pass through.
     */
    private static function validateHost(string $host): string
    {
        if ($host === '') {
            throw new \InvalidArgumentException(
                'Server Hostname/IP is empty - set the WHMCS server Hostname or IP Address'
            );
        }
        if (preg_match('/\s/', $host) === 1) {
            throw new \InvalidArgumentException(self::hostError($host, 'it contains whitespace'));
        }

        // A bare IPv6 literal is valid but has to be bracketed for the URL.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '[' . $host . ']';
        }
        // An already-bracketed IPv6 literal is kept; a bracketed value that is
        // not a clean [IPv6] (e.g. a trailing :port) is refused.
        if (str_starts_with($host, '[')) {
            if (
                preg_match('/^\[([0-9A-Fa-f:]+)\]$/', $host, $matches) === 1
                && filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ) {
                return $host;
            }
            throw new \InvalidArgumentException(self::hostError($host, 'it is not a valid [IPv6] literal'));
        }

        if (str_contains($host, '://')) {
            throw new \InvalidArgumentException(self::hostError($host, 'it includes a URL scheme'));
        }
        if (str_contains($host, '/') || str_contains($host, '\\')) {
            throw new \InvalidArgumentException(self::hostError($host, 'it includes a path or slash'));
        }
        if (str_contains($host, '@')) {
            throw new \InvalidArgumentException(self::hostError($host, 'it includes user information'));
        }
        if (str_contains($host, ':')) {
            throw new \InvalidArgumentException(
                self::hostError($host, 'it includes a port - set the port in the WHMCS Port field')
            );
        }

        // A plain IPv4 or a DNS host name is all that can remain; guard the
        // character set so no other stray punctuation reaches the URL.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $host) !== 1) {
            throw new \InvalidArgumentException(
                self::hostError($host, 'it contains characters that are not valid in a host name')
            );
        }

        return $host;
    }

    private static function hostError(string $host, string $reason): string
    {
        return sprintf(
            'Server Hostname/IP must be a bare host name or IP address (no scheme, port, path or user) - %s: "%s"',
            $reason,
            $host
        );
    }

    public function baseUrl(): string
    {
        $scheme = $this->secure ? 'https' : 'http';

        return $scheme . '://' . $this->host . ':' . $this->port;
    }

    public function endpointUrl(): string
    {
        return $this->baseUrl() . '/remote/json.php';
    }

    /** Panel login URL shown to humans (welcome email, client area). */
    public function panelUrl(): string
    {
        return $this->baseUrl() . '/';
    }
}
