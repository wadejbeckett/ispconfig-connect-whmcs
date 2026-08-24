<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Support\Params;
use WHMCS\Module\Server\Ispconfigconnect\Support\ServiceParams;

#[CoversClass(Params::class)]
#[CoversClass(ServiceParams::class)]
final class ParamsTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function serverParams(array $overrides = []): array
    {
        return array_merge([
            'serverhostname' => 'panel.example.com',
            'serverip' => '203.0.113.10',
            'serverport' => '8080',
            'serversecure' => 'on',
            'serverusername' => 'whmcs-remote',
            'serverpassword' => 'remote-secret',
            'serveraccesshash' => '',
        ], $overrides);
    }

    public function testServerConfigMapsCoreFields(): void
    {
        $config = Params::serverConfig($this->serverParams(['serverport' => '8443']));

        self::assertSame('panel.example.com', $config->host);
        self::assertSame(8443, $config->port);
        self::assertTrue($config->secure);
        self::assertSame('whmcs-remote', $config->username);
        self::assertSame('remote-secret', $config->password);
        self::assertTrue($config->verifyTls);
        self::assertNull($config->caBundle);
    }

    public function testServerConfigFallsBackToServerIpWhenHostnameEmpty(): void
    {
        $config = Params::serverConfig($this->serverParams(['serverhostname' => '']));

        self::assertSame('203.0.113.10', $config->host);
    }

    public function testServerConfigDefaultsPortTo8080WhenEmptyOrMissing(): void
    {
        self::assertSame(8080, Params::serverConfig($this->serverParams(['serverport' => '']))->port);

        $params = $this->serverParams();
        unset($params['serverport']);
        self::assertSame(8080, Params::serverConfig($params)->port);
    }

    public function testServerConfigSecureCheckboxOffMeansPlainHttp(): void
    {
        self::assertFalse(Params::serverConfig($this->serverParams(['serversecure' => '']))->secure);
    }

    public function testEmptyAccessHashMeansVerifiedTlsWithNoCaBundle(): void
    {
        $config = Params::serverConfig($this->serverParams(['serveraccesshash' => "  \n "]));

        self::assertTrue($config->verifyTls);
        self::assertNull($config->caBundle);
    }

    public function testAccessHashValueIsTreatedAsCaBundle(): void
    {
        $config = Params::serverConfig(
            $this->serverParams(['serveraccesshash' => ' /etc/ssl/certs/internal-ca.pem '])
        );

        self::assertTrue($config->verifyTls);
        self::assertSame('/etc/ssl/certs/internal-ca.pem', $config->caBundle);
    }

    public function testAccessHashLiteralPemIsPassedThroughVerbatim(): void
    {
        $pem = "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----";
        $config = Params::serverConfig($this->serverParams(['serveraccesshash' => $pem]));

        self::assertTrue($config->verifyTls);
        self::assertSame($pem, $config->caBundle);
    }

    public function testInsecurePrefixDisablesTlsVerification(): void
    {
        $config = Params::serverConfig($this->serverParams(['serveraccesshash' => 'insecure:']));

        self::assertFalse($config->verifyTls);
        self::assertNull($config->caBundle);
    }

    public function testInsecurePrefixRemainderIsOptionalCaBundle(): void
    {
        $config = Params::serverConfig(
            $this->serverParams(['serveraccesshash' => 'insecure: /etc/ssl/ca.pem '])
        );

        self::assertFalse($config->verifyTls);
        self::assertSame('/etc/ssl/ca.pem', $config->caBundle);
    }

    public function testBareInsecureWithoutColonIsJustACaBundleValue(): void
    {
        // The sentinel is the 'insecure:' PREFIX; the bare word is not special
        // (spec 2.5: "anything else is the CA bundle").
        $config = Params::serverConfig($this->serverParams(['serveraccesshash' => 'insecure']));

        self::assertTrue($config->verifyTls);
        self::assertSame('insecure', $config->caBundle);
    }

    /** @return array<string, mixed> */
    private function serviceRawParams(): array
    {
        return [
            'serviceid' => '4711',
            'domain' => 'example.org',
            'username' => 'exampleorg',
            'password' => 'hunter2!',
            'userid' => '99',
            'serverip' => '203.0.113.10',
            'configoptions' => ['ISPConfig Extra Mail' => 1],
            'clientsdetails' => [
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
                'fullname' => 'Ada Lovelace',
                'companyname' => 'Analytical Engines Ltd',
                'email' => 'ada@example.org',
                'address1' => '1 Byron Row',
                'city' => 'London',
                'state' => 'Greater London',
                'postcode' => 'W1 1AA',
                'country' => 'GB',
                'phonenumber' => '+44.2071234567',
                'language' => 'english',
            ],
        ];
    }

    public function testServiceMapsAllFields(): void
    {
        $service = Params::service($this->serviceRawParams());

        self::assertInstanceOf(ServiceParams::class, $service);
        self::assertSame(4711, $service->serviceId);
        self::assertSame('example.org', $service->domain);
        self::assertSame('exampleorg', $service->username);
        self::assertSame('hunter2!', $service->password);
        self::assertSame(99, $service->whmcsClientId);
        self::assertSame('203.0.113.10', $service->serverIp);
        self::assertSame('english', $service->language);
        self::assertSame(['ISPConfig Extra Mail' => 1], $service->configOptions);
        self::assertSame(
            [
                'name' => 'Ada Lovelace',
                'company' => 'Analytical Engines Ltd',
                'email' => 'ada@example.org',
                'address1' => '1 Byron Row',
                'city' => 'London',
                'state' => 'Greater London',
                'postcode' => 'W1 1AA',
                'country' => 'GB',
                'phone' => '+44.2071234567',
            ],
            $service->clientDetails
        );
    }

    public function testServiceComposesNameFromFirstAndLastWhenFullnameMissing(): void
    {
        $raw = $this->serviceRawParams();
        unset($raw['clientsdetails']['fullname']);

        self::assertSame('Ada Lovelace', Params::service($raw)->clientDetails['name']);
    }

    public function testServiceToleratesMissingOptionalKeys(): void
    {
        $service = Params::service(['serviceid' => 1]);

        self::assertSame(1, $service->serviceId);
        self::assertSame('', $service->domain);
        self::assertSame('', $service->username);
        self::assertSame('', $service->password);
        self::assertSame(0, $service->whmcsClientId);
        self::assertSame('', $service->serverIp);
        self::assertSame('', $service->language);
        self::assertSame([], $service->configOptions);
        self::assertSame('', $service->clientDetails['name']);
        self::assertSame('', $service->clientDetails['country']);
    }
}
