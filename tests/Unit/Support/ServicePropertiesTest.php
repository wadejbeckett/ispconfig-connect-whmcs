<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Support\Params;
use WHMCS\Module\Server\Ispconfigconnect\Support\ServiceProperties;

#[CoversClass(ServiceProperties::class)]
#[CoversClass(Params::class)]
final class ServicePropertiesTest extends TestCase
{
    /**
     * Plain-object double of WHMCS's $params['model']->serviceProperties
     * accessor: get(string) reads one property, save(array) upserts many.
     *
     * @param array<string, mixed> $initial
     */
    private function propertiesDouble(array $initial = []): object
    {
        return new class ($initial) {
            /** @param array<string, mixed> $store */
            public function __construct(public array $store)
            {
            }

            public function get(string $name): mixed
            {
                return $this->store[$name] ?? null;
            }

            /** @param array<string, mixed> $values */
            public function save(array $values): void
            {
                $this->store = array_merge($this->store, $values);
            }
        };
    }

    public function testGettersReturnNullWhenPropertyUnsetOrEmpty(): void
    {
        $props = new ServiceProperties($this->propertiesDouble([
            'ispconfig_domain_id' => '',
        ]));

        self::assertNull($props->clientId());
        self::assertNull($props->clientUsername());
        self::assertNull($props->domainId());
        self::assertNull($props->ftpUserId());
        self::assertNull($props->mailDomainId());
        self::assertNull($props->dnsZoneId());
        self::assertNull($props->databaseId());
        self::assertNull($props->databaseUserId());
    }

    public function testIntGettersCastStoredStrings(): void
    {
        $props = new ServiceProperties($this->propertiesDouble([
            'ispconfig_client_id' => '17',
            'ispconfig_domain_id' => 23,
            'ispconfig_ftp_user_id' => '8',
            'ispconfig_mail_domain_id' => '4',
            'ispconfig_dns_zone_id' => '12',
            'ispconfig_database_id' => '31',
            'ispconfig_database_user_id' => '30',
            'ispconfig_client_username' => 'web17user',
        ]));

        self::assertSame(17, $props->clientId());
        self::assertSame(23, $props->domainId());
        self::assertSame(8, $props->ftpUserId());
        self::assertSame(4, $props->mailDomainId());
        self::assertSame(12, $props->dnsZoneId());
        self::assertSame(31, $props->databaseId());
        self::assertSame(30, $props->databaseUserId());
        self::assertSame('web17user', $props->clientUsername());
    }

    public function testNonNumericStoredIdReadsAsNull(): void
    {
        $props = new ServiceProperties($this->propertiesDouble([
            'ispconfig_client_id' => 'corrupted',
        ]));

        self::assertNull($props->clientId());
    }

    public function testSettersPersistThroughSaveUnderTheCanonicalKeys(): void
    {
        $double = $this->propertiesDouble();
        $props = new ServiceProperties($double);

        $props->setClientId(17);
        $props->setClientUsername('web17user');
        $props->setDomainId(23);
        $props->setFtpUserId(8);
        $props->setMailDomainId(4);
        $props->setDnsZoneId(12);
        $props->setDatabaseId(31);
        $props->setDatabaseUserId(30);

        self::assertSame('17', $double->store['ispconfig_client_id']);
        self::assertSame('web17user', $double->store['ispconfig_client_username']);
        self::assertSame('23', $double->store['ispconfig_domain_id']);
        self::assertSame('8', $double->store['ispconfig_ftp_user_id']);
        self::assertSame('4', $double->store['ispconfig_mail_domain_id']);
        self::assertSame('12', $double->store['ispconfig_dns_zone_id']);
        self::assertSame('31', $double->store['ispconfig_database_id']);
        self::assertSame('30', $double->store['ispconfig_database_user_id']);

        // Round-trip through the typed getters.
        self::assertSame(17, $props->clientId());
        self::assertSame('web17user', $props->clientUsername());
    }

    public function testClearBlanksEveryManagedPropertyAndNothingElse(): void
    {
        $double = $this->propertiesDouble([
            'ispconfig_client_id' => '17',
            'ispconfig_client_username' => 'web17user',
            'ispconfig_domain_id' => '23',
            'unrelated_property' => 'keep-me',
        ]);
        $props = new ServiceProperties($double);

        $props->clear();

        foreach (ServiceProperties::KEYS as $key) {
            self::assertSame('', $double->store[$key], "expected $key blanked");
        }
        self::assertSame('keep-me', $double->store['unrelated_property']);
        self::assertNull($props->clientId());
        self::assertNull($props->clientUsername());
    }

    public function testConstructorRejectsObjectWithoutGetAndSave(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ServiceProperties(new \stdClass());
    }

    public function testParamsFactoryWrapsModelServiceProperties(): void
    {
        $double = $this->propertiesDouble(['ispconfig_client_id' => '5']);
        $model = new \stdClass();
        $model->serviceProperties = $double;

        $props = Params::serviceProperties(['model' => $model]);

        self::assertSame(5, $props->clientId());
    }

    public function testParamsFactoryThrowsWhenModelAbsent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Params::serviceProperties([]);
    }

    public function testParamsFactoryThrowsWhenModelLacksServiceProperties(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Params::serviceProperties(['model' => new \stdClass()]);
    }
}
