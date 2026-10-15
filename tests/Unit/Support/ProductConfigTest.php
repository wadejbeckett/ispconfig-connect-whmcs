<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Support\Params;
use WHMCS\Module\Server\Ispconfigconnect\Support\ProductConfig;

#[CoversClass(Params::class)]
#[CoversClass(ProductConfig::class)]
final class ProductConfigTest extends TestCase
{
    /**
     * Frozen option order per IMPLEMENTATION-NOTES §3.1 — do not renumber.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function rawOptions(array $overrides = []): array
    {
        return array_merge([
            'configoption1' => '5',            // Client Template id
            'configoption2' => '2',            // Web Server id
            'configoption3' => 'on',           // Create Website
            'configoption4' => 'on',           // Create FTP User
            'configoption5' => '',             // Create Mail Domain
            'configoption6' => '',             // Create DNS Zone
            'configoption7' => '3',            // DNS Template ID
            'configoption8' => 'ns1.example.net',
            'configoption9' => 'ns2.example.net',
            'configoption10' => 'hostmaster@example.net',
            'configoption11' => '',            // Create Database
            'configoption12' => 'php-fpm',     // PHP Mode
            'configoption13' => 'on',          // Suspend Entire Client
            'configoption14' => '',            // Panel URL Override
        ], $overrides);
    }

    public function testMapsEveryOptionPerFrozenOrder(): void
    {
        $config = Params::productConfig($this->rawOptions([
            'configoption5' => 'on',
            'configoption6' => 'on',
            'configoption11' => 'on',
            'configoption14' => 'https://panel.example.net:8080/',
        ]));

        self::assertSame(5, $config->clientTemplateId);
        self::assertSame(2, $config->webServerId);
        self::assertTrue($config->createWebsite);
        self::assertTrue($config->createFtpUser);
        self::assertTrue($config->createMailDomain);
        self::assertTrue($config->createDnsZone);
        self::assertSame(3, $config->dnsTemplateId);
        self::assertSame('ns1.example.net', $config->ns1);
        self::assertSame('ns2.example.net', $config->ns2);
        self::assertSame('hostmaster@example.net', $config->dnsAdminEmail);
        self::assertTrue($config->createDatabase);
        self::assertSame('php-fpm', $config->phpMode);
        self::assertTrue($config->suspendEntireClient);
        self::assertSame('https://panel.example.net:8080/', $config->panelUrlOverride);
    }

    public function testUncheckedCheckboxesArriveAsEmptyStringAndMapToFalse(): void
    {
        $config = Params::productConfig($this->rawOptions([
            'configoption3' => '',
            'configoption4' => '',
            'configoption13' => '',
        ]));

        self::assertFalse($config->createWebsite);
        self::assertFalse($config->createFtpUser);
        self::assertFalse($config->createMailDomain);
        self::assertFalse($config->createDnsZone);
        self::assertFalse($config->createDatabase);
        self::assertFalse($config->suspendEntireClient);
    }

    public function testMissingOptionKeysBehaveAsUnset(): void
    {
        $config = Params::productConfig([]);

        self::assertSame(0, $config->clientTemplateId);
        self::assertSame(0, $config->webServerId);
        self::assertFalse($config->createWebsite);
        self::assertFalse($config->createFtpUser);
        self::assertNull($config->panelUrlOverride);
    }

    public function testNonNumericTemplateAndServerIdsCastToZeroUnset(): void
    {
        $config = Params::productConfig($this->rawOptions([
            'configoption1' => '',
            'configoption2' => 'not-an-id',
        ]));

        self::assertSame(0, $config->clientTemplateId);
        self::assertSame(0, $config->webServerId);
    }

    public function testDnsTemplateIdDefaultsToOneWhenBlank(): void
    {
        $config = Params::productConfig($this->rawOptions(['configoption7' => ' ']));

        self::assertSame(1, $config->dnsTemplateId);
    }

    public function testPhpModeDefaultsToPhpFpmWhenBlank(): void
    {
        $config = Params::productConfig($this->rawOptions(['configoption12' => '']));

        self::assertSame('php-fpm', $config->phpMode);
    }

    public function testPanelUrlOverrideIsNullWhenBlankAndTrimmedOtherwise(): void
    {
        self::assertNull(
            Params::productConfig($this->rawOptions(['configoption14' => '   ']))->panelUrlOverride
        );
        self::assertSame(
            'https://cp.example.net',
            Params::productConfig(
                $this->rawOptions(['configoption14' => ' https://cp.example.net '])
            )->panelUrlOverride
        );
    }
}
