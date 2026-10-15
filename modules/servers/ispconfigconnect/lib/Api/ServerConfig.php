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
     * The validated, connection-ready host: a bare ASCII DNS host name (an
     * internationalised name arrives here in its punycode "xn--" form), a
     * plain IPv4, or a BRACKETED IPv6 literal. It is concatenated straight into
     * the URL that carries the remote-API password, so it is never anything
     * else.
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
     * (TestConnection catches it and shows it), never silently "cleaned". Two
     * transforms are made, both of which the URL itself requires: bracketing a
     * bare IPv6 literal, and converting an internationalised (IDN) host name to
     * its ASCII punycode form - see toAsciiHost(). Plain host names and plain
     * IPv4 addresses pass through.
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

        // Scheme, path, userinfo and port are refused on the value AS TYPED,
        // above; only then is an IDN name converted, and the converted form
        // goes through the same checks below as a name typed in ASCII.
        $original = $host;
        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $host = self::toAsciiHost($host, function_exists('idn_to_ascii'));
        }

        // A plain IPv4 or a DNS host name is all that can remain; guard the
        // character set so no other stray punctuation reaches the URL.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $host) !== 1) {
            throw new \InvalidArgumentException(self::hostError(
                $original,
                'it contains characters that are not valid in a host name'
                    . ($host !== $original ? sprintf(' (as ASCII it reads "%s")', $host) : '')
            ));
        }

        return $host;
    }

    /**
     * An internationalised host name ("bücher.example") in its ASCII punycode
     * form ("xn--bcher-kva.example"), the only form a URL - and the TLS
     * certificate's subject names - can carry. Before host validation existed,
     * curl did this conversion itself, so refusing such a host outright would
     * break a server record that used to work.
     *
     * UTS #46 non-transitional processing is IDNA2008 as browsers and curl's
     * libidn2 apply it ("ß" stays "ß" and is encoded, not folded to "ss"), and
     * it is the same conversion Support\Params applies to customer domains.
     * UTS #46 also MAPS look-alike characters - a fullwidth "／", "：" or "＠"
     * becomes an ASCII "/", ":" or "@" - so the result is NOT trusted: the
     * caller runs it through the same character-set check as a typed ASCII
     * name, which is what stops a mapped slash or colon from retargeting the
     * password POST.
     *
     * Without ext-intl there is no conversion to make, so the host is refused
     * with a message telling the admin what to enter instead. $intlAvailable is
     * the caller's function_exists() result, taken as a parameter so the
     * no-intl branch is testable on a host that has the extension.
     */
    private static function toAsciiHost(string $host, bool $intlAvailable): string
    {
        if (!$intlAvailable) {
            throw new \InvalidArgumentException(self::hostError(
                $host,
                'it contains non-ASCII characters and the PHP intl extension is not loaded to convert '
                    . 'them - enter the host name in its ASCII punycode form instead (it starts with '
                    . '"xn--", e.g. "xn--bcher-kva.example" for "bücher.example")'
            ));
        }

        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if (!is_string($ascii) || $ascii === '') {
            throw new \InvalidArgumentException(
                self::hostError($host, 'it is not a valid internationalised (IDN) host name')
            );
        }

        return $ascii;
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
