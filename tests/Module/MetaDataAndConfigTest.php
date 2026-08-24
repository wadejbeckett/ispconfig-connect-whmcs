<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

/**
 * Static module surface: MetaData, the frozen ConfigOptions order
 * (IMPLEMENTATION-NOTES §3.1 - repositioning after ship would silently
 * reassign every existing product's settings), whmcs.json metadata, and the
 * client-area template asset.
 */
final class MetaDataAndConfigTest extends ModuleTestCase
{
    private const MODULE_DIR = __DIR__ . '/../../modules/servers/ispconfigconnect';

    public function testMetaData(): void
    {
        $meta = ispconfigconnect_MetaData();

        self::assertSame('ISPConfig Connect', $meta['DisplayName']);
        self::assertSame('1.1', $meta['APIVersion']);
        self::assertTrue($meta['RequiresServer']);
        self::assertSame('8080', (string) $meta['DefaultSSLPort']);
        self::assertSame('8080', (string) $meta['DefaultNonSSLPort']);
        // SSO is Phase 2 - advertising a label now would render a dead button.
        self::assertArrayNotHasKey('ServiceSingleSignOnLabel', $meta);
        self::assertArrayNotHasKey('AdminSingleSignOnLabel', $meta);
    }

    public function testConfigOptionsFrozenOrder(): void
    {
        $options = ispconfigconnect_ConfigOptions();

        self::assertSame(
            [
                'Client Template',
                'Web Server',
                'Create Website',
                'Create FTP User',
                'Create Mail Domain',
                'Create DNS Zone',
                'DNS Template ID',
                'NS1',
                'NS2',
                'DNS Admin Email',
                'Create Database',
                'PHP Mode',
                'Suspend Also Disables Website',
                'Panel URL Override',
            ],
            array_keys($options)
        );
    }

    public function testConfigOptionTypesAndDefaults(): void
    {
        $options = ispconfigconnect_ConfigOptions();

        self::assertSame('dropdown', $options['Client Template']['Type']);
        self::assertSame('ispconfigconnect_ClientTemplateLoader', $options['Client Template']['Loader']);
        self::assertSame('dropdown', $options['Web Server']['Type']);
        self::assertSame('ispconfigconnect_WebServerLoader', $options['Web Server']['Loader']);

        foreach (
            [
                'Create Website',
                'Create FTP User',
                'Create Mail Domain',
                'Create DNS Zone',
                'Create Database',
                'Suspend Also Disables Website',
            ] as $checkbox
        ) {
            self::assertSame('yesno', $options[$checkbox]['Type'], $checkbox);
        }
        self::assertSame('on', $options['Create Website']['Default']);
        self::assertSame('on', $options['Create FTP User']['Default']);
        self::assertSame('on', $options['Suspend Also Disables Website']['Default']);

        self::assertSame('text', $options['DNS Template ID']['Type']);
        self::assertSame('1', $options['DNS Template ID']['Default']);
        self::assertSame('text', $options['NS1']['Type']);
        self::assertSame('text', $options['NS2']['Type']);
        self::assertSame('text', $options['DNS Admin Email']['Type']);
        self::assertSame('text', $options['Panel URL Override']['Type']);

        self::assertSame('dropdown', $options['PHP Mode']['Type']);
        self::assertSame(
            ['php-fpm', 'fast-cgi', 'mod', 'suphp', 'cgi', 'no'],
            array_keys($options['PHP Mode']['Options'])
        );
        self::assertSame('php-fpm', $options['PHP Mode']['Default']);
    }

    public function testWhmcsJsonMetadata(): void
    {
        $path = self::MODULE_DIR . '/whmcs.json';
        self::assertFileExists($path);

        $metadata = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('1.0', $metadata['schema']);
        self::assertSame('whmcs-servers', $metadata['type']);
        self::assertSame('ispconfigconnect', $metadata['name']);
        self::assertSame('ISPConfig Connect', $metadata['description']['name']);
        self::assertSame('0.1.0', $metadata['version']);
        self::assertStringContainsString('GPL', (string) $metadata['license']);
        self::assertSame('provisioning', $metadata['category']);
        self::assertArrayNotHasKey('logo', $metadata);
    }

    public function testOverviewTemplateExistsAndNeverMentionsAPassword(): void
    {
        $path = self::MODULE_DIR . '/templates/overview.tpl';
        self::assertFileExists($path);

        $template = (string) file_get_contents($path);
        self::assertStringContainsString('{$panelUrl}', $template);
        self::assertStringContainsString('{$ispconfigUsername}', $template);
        // Design §3.6.3: no password (or any credential) is ever rendered.
        self::assertStringNotContainsStringIgnoringCase('password', $template);
    }
}
