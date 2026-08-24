<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Provision;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Provision\Mapper;
use WHMCS\Module\Server\Ispconfigconnect\Support\ProductConfig;
use WHMCS\Module\Server\Ispconfigconnect\Support\ServiceParams;

#[CoversClass(Mapper::class)]
final class MapperTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function product(array $overrides = []): ProductConfig
    {
        $defaults = [
            'clientTemplateId' => 5,
            'webServerId' => 2,
            'createWebsite' => true,
            'createFtpUser' => true,
            'createMailDomain' => false,
            'createDnsZone' => false,
            'dnsTemplateId' => 1,
            'ns1' => 'ns1.example.net',
            'ns2' => 'ns2.example.net',
            'dnsAdminEmail' => 'hostmaster@example.net',
            'createDatabase' => false,
            'phpMode' => 'php-fpm',
            'suspendAlsoDisablesWebsite' => true,
            'panelUrlOverride' => null,
        ];

        return new ProductConfig(...array_merge($defaults, $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function service(array $overrides = []): ServiceParams
    {
        $defaults = [
            'serviceId' => 42,
            'domain' => 'example.com',
            'username' => 'exampleuser',
            'password' => 'S3cret!pass',
            'clientDetails' => [
                'name' => 'Jane Doe',
                'company' => 'Acme GmbH',
                'email' => 'jane@example.com',
                'address1' => 'Main Street 1',
                'city' => 'Springfield',
                'state' => 'IL',
                'postcode' => '62701',
                'country' => 'US',
                'phone' => '+1.5551234567',
            ],
            'whmcsClientId' => 77,
            'serverIp' => '203.0.113.10',
            'language' => 'english',
            'configOptions' => [],
        ];

        return new ServiceParams(...array_merge($defaults, $overrides));
    }

    // ── clientAddParams ────────────────────────────────────────────────

    public function testClientAddParamsMapsContactAndModuleFields(): void
    {
        $params = Mapper::clientAddParams($this->product(), $this->service());

        // Field names per interface/web/client/form/client.tform.php.
        self::assertSame('Jane Doe', $params['contact_name']);
        self::assertSame('Acme GmbH', $params['company_name']);
        self::assertSame('jane@example.com', $params['email']);
        self::assertSame('Main Street 1', $params['street']);
        self::assertSame('Springfield', $params['city']);
        self::assertSame('IL', $params['state']);
        self::assertSame('62701', $params['zip']);
        self::assertSame('US', $params['country']);
        self::assertSame('+1.5551234567', $params['telephone']);
        self::assertSame('exampleuser', $params['username']);
        self::assertSame('S3cret!pass', $params['password']);
        self::assertSame(5, $params['template_master']);
        self::assertSame('', $params['template_additional']);
        self::assertSame(2, $params['default_webserver']);
        self::assertSame(2, $params['default_mailserver']);
        self::assertSame(2, $params['default_dnsserver']);
        self::assertSame(2, $params['default_dbserver']);
        self::assertSame('Managed by WHMCS service #42', $params['notes']);
    }

    public function testUserthemeIsAlwaysPresentAndDefaultEvenWhenNothingSetsIt(): void
    {
        // Minimal service: nothing anywhere mentions a theme. The key must
        // still be there — ISPConfig 3.3.1 bug #6990 rejects client_add
        // without it (usertheme REGEX runs against '' for missing fields).
        $service = $this->service([
            'clientDetails' => [
                'name' => 'X',
                'company' => '',
                'email' => 'x@example.com',
                'address1' => '',
                'city' => '',
                'state' => '',
                'postcode' => '',
                'country' => '',
                'phone' => '',
            ],
            'language' => '',
        ]);

        $params = Mapper::clientAddParams($this->product(), $service);

        self::assertArrayHasKey('usertheme', $params);
        self::assertSame('default', $params['usertheme']);

        // And in the fully-populated case too.
        $full = Mapper::clientAddParams($this->product(), $this->service());
        self::assertSame('default', $full['usertheme']);
    }

    public function testClientAddParamsLanguageMapping(): void
    {
        $lang = fn (string $whmcsLanguage): string => Mapper::clientAddParams(
            $this->product(),
            $this->service(['language' => $whmcsLanguage])
        )['language'];

        self::assertSame('en', $lang('english'));
        self::assertSame('de', $lang('german'));
        self::assertSame('br', $lang('portuguese-br'));
        self::assertSame('cz', $lang('czech'));
        self::assertSame('en', $lang(''));            // empty → default
        self::assertSame('en', $lang('klingon'));     // unknown → default
        self::assertSame('de', $lang('De'));          // already a code
        self::assertSame('en', $lang('xx'));          // code ISPConfig lacks
    }

    // ── webDomainAddParams ─────────────────────────────────────────────

    public function testWebDomainAddParamsShape(): void
    {
        $params = Mapper::webDomainAddParams(
            $this->product(['phpMode' => 'fast-cgi']),
            $this->service()
        );

        self::assertSame(2, $params['server_id']);
        self::assertSame('*', $params['ip_address']);
        self::assertSame('example.com', $params['domain']);
        self::assertSame('vhost', $params['type']);
        self::assertSame(0, $params['parent_domain_id']);
        self::assertSame('www', $params['subdomain']);
        self::assertSame('fast-cgi', $params['php']);
        self::assertSame(-1, $params['hd_quota']);
        self::assertSame(-1, $params['traffic_quota']);
        self::assertSame('All', $params['allow_override']);
        self::assertSame('y', $params['active']);
        self::assertSame(1, $params['errordocs']);
    }

    // ── ftpUserAddParams ───────────────────────────────────────────────

    public function testFtpUserAddParamsUsesFetchedWebDomainRecord(): void
    {
        $webDomain = [
            'domain_id' => 9,
            'server_id' => 2,
            'domain' => 'example.com',
            'system_user' => 'web9',
            'system_group' => 'client7',
            'document_root' => '/var/www/clients/client7/web9',
        ];

        $params = Mapper::ftpUserAddParams($this->service(), $webDomain);

        self::assertSame(2, $params['server_id']);
        self::assertSame(9, $params['parent_domain_id']);
        // Username is the service username verbatim — ISPConfig's interface
        // prefixing does not apply over the remote API.
        self::assertSame('exampleuser', $params['username']);
        self::assertSame('S3cret!pass', $params['password']);
        self::assertSame('web9', $params['uid']);
        self::assertSame('client7', $params['gid']);
        self::assertSame('/var/www/clients/client7/web9', $params['dir']);
        self::assertSame(-1, $params['quota_size']);
        self::assertSame('y', $params['active']);
    }

    // ── mailDomainAddParams ────────────────────────────────────────────

    public function testMailDomainAddParamsShape(): void
    {
        $params = Mapper::mailDomainAddParams($this->product(), $this->service());

        self::assertSame(2, $params['server_id']);
        self::assertSame('example.com', $params['domain']);
        self::assertSame('y', $params['active']);
        // dkim_selector REGEX in mail_domain.tform.php has no allowempty:
        // a missing field validates as '' and fails, same failure mode as
        // usertheme — so the selector must be sent explicitly.
        self::assertSame('default', $params['dkim_selector']);
    }

    // ── databaseNames ──────────────────────────────────────────────────

    public function testDatabaseNamesDeterministicSchemeAndSharedByUserAndName(): void
    {
        $first = Mapper::databaseNames($this->service(), 7);
        $second = Mapper::databaseNames($this->service(), 7);

        self::assertSame('c7_example', $first['name']);
        self::assertSame('c7_example', $first['user']);
        self::assertSame($first, $second);
    }

    public function testDatabaseNamesNeverExceedSixteenCharsAndKeepClientPrefix(): void
    {
        $service = $this->service(['domain' => 'averyverylongdomainname.example.com']);

        $names = Mapper::databaseNames($service, 123);

        self::assertSame(16, strlen($names['name']));
        self::assertStringStartsWith('c123_', $names['name']);
        self::assertSame('c123_averyverylo', $names['name']);
        self::assertSame($names['name'], $names['user']);
    }

    public function testDatabaseNamesAreCollisionFreeAcrossClients(): void
    {
        $service = $this->service(['domain' => 'averyverylongdomainname.example.com']);

        $a = Mapper::databaseNames($service, 123);
        $b = Mapper::databaseNames($service, 124);

        self::assertNotSame($a['name'], $b['name']);
    }

    public function testDatabaseNamesSanitizeSldToLowercaseAlnum(): void
    {
        $names = Mapper::databaseNames($this->service(['domain' => 'My-Site.co.uk']), 3);

        self::assertSame('c3_mysite', $names['name']);
    }

    public function testDatabaseNamesWithDotlessDomainUseWholeLabel(): void
    {
        $names = Mapper::databaseNames($this->service(['domain' => 'localdomain']), 4);

        self::assertSame('c4_localdomain', $names['name']);
    }

    // ── dnsTemplateArgs ────────────────────────────────────────────────

    public function testDnsTemplateArgsMapping(): void
    {
        $args = Mapper::dnsTemplateArgs(
            $this->product(['dnsTemplateId' => 3]),
            $this->service()
        );

        // Keys are dns_templatezone_add()'s PHP parameter names
        // (interface/lib/classes/remote.d/dns.inc.php) — the JSON handler
        // matches body keys to parameter names by reflection.
        self::assertSame(3, $args['template_id']);
        self::assertSame('example.com', $args['domain']);
        self::assertSame('203.0.113.10', $args['ip']);
        self::assertSame('ns1.example.net', $args['ns1']);
        self::assertSame('ns2.example.net', $args['ns2']);
        self::assertSame('hostmaster@example.net', $args['email']);
    }

    public function testDnsTemplateArgsEmailFallsBackToAdminAtDomain(): void
    {
        $args = Mapper::dnsTemplateArgs(
            $this->product(['dnsAdminEmail' => '']),
            $this->service()
        );

        self::assertSame('admin@example.com', $args['email']);
    }

    public function testDnsTemplateArgsEmailFallbackTrimsWhitespaceOnlyOption(): void
    {
        $args = Mapper::dnsTemplateArgs(
            $this->product(['dnsAdminEmail' => '   ']),
            $this->service()
        );

        self::assertSame('admin@example.com', $args['email']);
    }

    // ── addonTemplateIds (changePackage mapping) ───────────────────────

    /**
     * Rows shaped like client_templates_get_all output: SELECT * FROM
     * client_template (remote.d/client.inc.php), string-typed values as
     * PDO returns them, limit columns included as noise.
     *
     * @return list<array<string, string>>
     */
    private function templateRows(): array
    {
        return [
            ['template_id' => '1', 'template_name' => 'Gold', 'template_type' => 'm', 'limit_web_domain' => '5'],
            ['template_id' => '2', 'template_name' => 'Extra Mail', 'template_type' => 'a', 'limit_maildomain' => '10'],
            ['template_id' => '3', 'template_name' => 'Turbo', 'template_type' => 'a', 'limit_traffic_quota' => '-1'],
            // Master template that shares a name with an addon-looking
            // option — must never be matched (type 'a' rows only).
            ['template_id' => '4', 'template_name' => 'SSH Access', 'template_type' => 'm', 'limit_shell_user' => '1'],
            ['template_id' => '5', 'template_name' => 'Extra', 'template_type' => 'a', 'limit_web_domain' => '1'],
        ];
    }

    public function testAddonTemplateIdsResolveByExactNameAgainstTypeARowsOnly(): void
    {
        $service = $this->service([
            'configOptions' => [
                'ISPConfig Extra Mail' => 1,
                'ISPConfig Turbo' => '1',
                'Disk Space' => '10240',        // reserved option, not addon-prefixed
                'Some Other Option' => 'value', // unrelated option
            ],
        ]);

        self::assertSame([2, 3], Mapper::addonTemplateIds($service, $this->templateRows()));
    }

    public function testAddonTemplateIdsSkipDisabledOptions(): void
    {
        $service = $this->service([
            'configOptions' => [
                'ISPConfig Extra Mail' => 0,
                'ISPConfig Turbo' => '',
                'ISPConfig Extra' => '0',
            ],
        ]);

        self::assertSame([], Mapper::addonTemplateIds($service, $this->templateRows()));
    }

    public function testAddonTemplateIdsRequireExactNameMatchNotSubstring(): void
    {
        // 'Extra' exists as an addon template — 'Extra Mail' must not
        // resolve to it, nor vice versa.
        $service = $this->service([
            'configOptions' => ['ISPConfig Extra' => 1],
        ]);

        self::assertSame([5, ], Mapper::addonTemplateIds($service, $this->templateRows()));
    }

    public function testAddonTemplateIdsNeverMatchMasterTemplates(): void
    {
        // 'SSH Access' only exists as a master (type 'm') template.
        $service = $this->service([
            'configOptions' => ['ISPConfig SSH Access' => 1],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SSH Access');

        Mapper::addonTemplateIds($service, $this->templateRows());
    }

    public function testAddonTemplateIdsThrowOnUnknownAddonName(): void
    {
        $service = $this->service([
            'configOptions' => ['ISPConfig No Such Template' => 1],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No Such Template');

        Mapper::addonTemplateIds($service, $this->templateRows());
    }
}
