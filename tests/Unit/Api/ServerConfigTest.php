<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\ServerConfig;

/**
 * The Hostname / IP field is concatenated straight into the URL that POSTs the
 * remote-API password (and the SSO redirect target). A trailing slash, scheme,
 * embedded port or userinfo silently retargets that POST, so ServerConfig
 * refuses a malformed value loudly rather than "cleaning" it.
 */
#[CoversClass(ServerConfig::class)]
final class ServerConfigTest extends TestCase
{
    private static function config(string $host): ServerConfig
    {
        return new ServerConfig(host: $host, username: 'remote', password: 'pw', port: 8080);
    }

    // ---- accepted forms ----------------------------------------------------

    public function testPlainHostnameIsAcceptedAndBuildsTheEndpointUrl(): void
    {
        $config = self::config('panel.example.com');

        self::assertSame('panel.example.com', $config->host);
        self::assertSame('https://panel.example.com:8080/remote/json.php', $config->endpointUrl());
    }

    public function testPlainIpv4IsAccepted(): void
    {
        self::assertSame('203.0.113.10', self::config('203.0.113.10')->host);
    }

    public function testSingleLabelHostnameIsAccepted(): void
    {
        self::assertSame('panel', self::config('panel')->host);
    }

    public function testBareIpv6IsBracketedForTheUrl(): void
    {
        $config = self::config('2001:db8::10');

        self::assertSame('[2001:db8::10]', $config->host);
        self::assertSame('https://[2001:db8::10]:8080/remote/json.php', $config->endpointUrl());
    }

    public function testAlreadyBracketedIpv6IsAcceptedAsIs(): void
    {
        self::assertSame('[2001:db8::10]', self::config('[2001:db8::10]')->host);
    }

    // ---- rejected forms ----------------------------------------------------

    /** @return array<string, array{string}> */
    public static function malformedHosts(): array
    {
        return [
            'trailing slash' => ['panel.example.com/'],
            'path' => ['panel.example.com/remote'],
            'scheme' => ['https://panel.example.com'],
            'scheme and path' => ['http://panel.example.com/'],
            'embedded port' => ['panel.example.com:8080'],
            'userinfo' => ['remote@panel.example.com'],
            'bracketed ipv6 with port' => ['[2001:db8::10]:8080'],
            'malformed bracket' => ['[not:an:ipv6'],
            'internal whitespace' => ['panel .example.com'],
            'surrounding whitespace' => [" panel.example.com\n"],
            'empty' => [''],
            'backslash' => ['panel.example.com\\admin'],
        ];
    }

    #[DataProvider('malformedHosts')]
    public function testMalformedHostIsRejected(string $host): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::config($host);
    }

    public function testTheRejectionMessageNamesTheProblemAndTheValue(): void
    {
        try {
            self::config('panel.example.com/');
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('path or slash', $exception->getMessage());
            self::assertStringContainsString('panel.example.com/', $exception->getMessage());
        }
    }

    public function testAnEmbeddedPortIsNamedInTheMessage(): void
    {
        try {
            self::config('panel.example.com:8080');
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('port', $exception->getMessage());
        }
    }
}
