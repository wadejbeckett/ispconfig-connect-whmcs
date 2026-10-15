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

    // ---- internationalised (IDN) host names ---------------------------------
    //
    // Before host validation existed curl IDN-encoded a Unicode host itself, so
    // a server record like "bücher.example" worked; refusing it outright broke
    // every operation on that server. It is converted to punycode when ext-intl
    // is there, and refused with instructions when it is not.

    private static function requireIntl(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('ext-intl is not loaded; the no-intl message path is covered separately');
        }
    }

    public function testAnIdnHostIsConvertedToPunycode(): void
    {
        self::requireIntl();

        $config = self::config('bücher.example');

        self::assertSame('xn--bcher-kva.example', $config->host);
        self::assertSame('https://xn--bcher-kva.example:8080/remote/json.php', $config->endpointUrl());
        self::assertSame('https://xn--bcher-kva.example:8080/', $config->panelUrl());
    }

    public function testIdnConversionIsNonTransitionalLikeBrowsersAndCurl(): void
    {
        self::requireIntl();

        // IDNA2008 / UTS #46 non-transitional: "ß" is encoded, not folded to
        // "ss" - otherwise the module would connect to a DIFFERENT host than a
        // browser opening the same name. Upper case is folded.
        self::assertSame('xn--strae-oqa.example', self::config('straße.example')->host);
        self::assertSame('xn--bcher-kva.example', self::config('BÜCHER.example')->host);
    }

    /** @return array<string, array{string}> */
    public static function idnHostsThatMapToUrlPunctuation(): array
    {
        // UTS #46 maps these look-alikes to ASCII punctuation - the converted
        // form must be re-validated, or they smuggle a path, port or userinfo
        // back into the URL that carries the remote-API password.
        return [
            'fullwidth solidus -> path' => ['panel.example．com／remote'],
            'fullwidth colon -> port' => ['bücher.example：8443'],
            'fullwidth at -> userinfo' => ['ｕｓｅｒ＠bücher.example'],
            'ideographic space' => ["bücher.example\u{3000}"],
            'no-break space' => ["bücher\u{00A0}.example"],
        ];
    }

    #[DataProvider('idnHostsThatMapToUrlPunctuation')]
    public function testAnIdnHostThatMapsToUrlPunctuationIsRejected(string $host): void
    {
        self::requireIntl();

        try {
            self::config($host);
            self::fail('Expected InvalidArgumentException for ' . $host);
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('not valid in a host name', $exception->getMessage());
            // The admin sees both what they typed and what it became.
            self::assertStringContainsString($host, $exception->getMessage());
            self::assertStringContainsString('as ASCII it reads', $exception->getMessage());
        }
    }

    public function testAnInvalidIdnHostIsRejectedWithAnIdnMessage(): void
    {
        self::requireIntl();

        try {
            self::config('-bücher.example'); // a label may not start with a hyphen
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('not a valid internationalised (IDN) host name', $exception->getMessage());
        }
    }

    public function testSchemePortPathAndUserinfoAroundAnIdnHostAreStillRefusedAsTyped(): void
    {
        // Checked on the value AS TYPED, before any conversion, so the message
        // names the real problem rather than an IDN failure.
        $cases = [
            'https://bücher.example' => 'URL scheme',
            'bücher.example/' => 'path or slash',
            'admin@bücher.example' => 'user information',
            'bücher.example:8080' => 'port',
        ];
        foreach ($cases as $host => $named) {
            try {
                self::config($host);
                self::fail('Expected InvalidArgumentException for ' . $host);
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString($named, $exception->getMessage(), $host);
            }
        }
    }

    public function testWithoutIntlAnIdnHostIsRefusedWithThePunycodeInstruction(): void
    {
        // Runs whether or not ext-intl is loaded: the helper takes the
        // function_exists() result as a flag, so the no-intl branch is driven
        // directly.
        $toAscii = new \ReflectionMethod(ServerConfig::class, 'toAsciiHost');

        try {
            $toAscii->invoke(null, 'bücher.example', false);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            $message = $exception->getMessage();
            self::assertStringContainsString('bücher.example', $message);
            self::assertStringContainsString('intl extension is not loaded', $message);
            self::assertStringContainsString('punycode', $message);
            self::assertStringContainsString('xn--', $message);
        }
    }

    public function testAnAsciiPunycodeHostIsAcceptedAsTypedWithOrWithoutIntl(): void
    {
        // What the no-intl message tells the admin to enter must itself work.
        self::assertSame('xn--bcher-kva.example', self::config('xn--bcher-kva.example')->host);
    }
}
