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
    public function __construct(
        public readonly string $host,
        public readonly string $username,
        #[\SensitiveParameter]
        public readonly string $password,
        public readonly int $port = 8080,
        public readonly bool $secure = true,
        public readonly bool $verifyTls = true,
        public readonly ?string $caBundle = null,
        public readonly int $timeoutSeconds = 30,
    ) {
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
