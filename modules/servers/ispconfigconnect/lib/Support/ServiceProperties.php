<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Support;

/**
 * Typed accessor over WHMCS service properties — the per-service key/value
 * store where the module records every ISPConfig id it created.
 *
 * Wraps the object at $params['model']->serviceProperties, which follows the
 * WHMCS convention get(string $name): mixed / save(array $values): void.
 * Unit tests inject a plain-object double with the same two methods, so the
 * wrapped object is duck-typed rather than type-hinted to a WHMCS class.
 *
 * WHMCS persists property values as text; unset properties read back as
 * null or '' depending on WHMCS version — both mean "not stored" here.
 */
final class ServiceProperties
{
    public const KEY_CLIENT_ID = 'ispconfig_client_id';
    public const KEY_CLIENT_USERNAME = 'ispconfig_client_username';
    public const KEY_DOMAIN_ID = 'ispconfig_domain_id';
    public const KEY_FTP_USER_ID = 'ispconfig_ftp_user_id';
    public const KEY_MAIL_DOMAIN_ID = 'ispconfig_mail_domain_id';
    public const KEY_DNS_ZONE_ID = 'ispconfig_dns_zone_id';
    public const KEY_DATABASE_ID = 'ispconfig_database_id';
    public const KEY_DATABASE_USER_ID = 'ispconfig_database_user_id';

    /** @var list<string> every property this module manages */
    public const KEYS = [
        self::KEY_CLIENT_ID,
        self::KEY_CLIENT_USERNAME,
        self::KEY_DOMAIN_ID,
        self::KEY_FTP_USER_ID,
        self::KEY_MAIL_DOMAIN_ID,
        self::KEY_DNS_ZONE_ID,
        self::KEY_DATABASE_ID,
        self::KEY_DATABASE_USER_ID,
    ];

    private readonly object $properties;

    /**
     * WHMCS resolves $model->serviceProperties against custom fields scoped
     * to the service's CURRENT product (live-verified: tblcustomfields rows
     * carry relid = product id), so after a product switch every read
     * misses. The fallback reader - wired by the entry file with a direct
     * fieldname+serviceid lookup - keeps properties readable across product
     * changes. @var (\Closure(string): ?string)|null
     */
    private ?\Closure $fallbackReader = null;

    public function __construct(object $properties)
    {
        if (!method_exists($properties, 'get') || !method_exists($properties, 'save')) {
            throw new \InvalidArgumentException(
                'Service properties object must expose get(string) and save(array) '
                . '(the WHMCS $params[\'model\']->serviceProperties convention).'
            );
        }
        $this->properties = $properties;
    }

    public function clientId(): ?int
    {
        return $this->intValue(self::KEY_CLIENT_ID);
    }

    public function setClientId(int $value): void
    {
        $this->saveValue(self::KEY_CLIENT_ID, (string) $value);
    }

    public function clientUsername(): ?string
    {
        return $this->stringValue(self::KEY_CLIENT_USERNAME);
    }

    public function setClientUsername(string $value): void
    {
        $this->saveValue(self::KEY_CLIENT_USERNAME, $value);
    }

    public function domainId(): ?int
    {
        return $this->intValue(self::KEY_DOMAIN_ID);
    }

    public function setDomainId(int $value): void
    {
        $this->saveValue(self::KEY_DOMAIN_ID, (string) $value);
    }

    public function ftpUserId(): ?int
    {
        return $this->intValue(self::KEY_FTP_USER_ID);
    }

    public function setFtpUserId(int $value): void
    {
        $this->saveValue(self::KEY_FTP_USER_ID, (string) $value);
    }

    public function mailDomainId(): ?int
    {
        return $this->intValue(self::KEY_MAIL_DOMAIN_ID);
    }

    public function setMailDomainId(int $value): void
    {
        $this->saveValue(self::KEY_MAIL_DOMAIN_ID, (string) $value);
    }

    public function dnsZoneId(): ?int
    {
        return $this->intValue(self::KEY_DNS_ZONE_ID);
    }

    public function setDnsZoneId(int $value): void
    {
        $this->saveValue(self::KEY_DNS_ZONE_ID, (string) $value);
    }

    public function databaseId(): ?int
    {
        return $this->intValue(self::KEY_DATABASE_ID);
    }

    public function setDatabaseId(int $value): void
    {
        $this->saveValue(self::KEY_DATABASE_ID, (string) $value);
    }

    public function databaseUserId(): ?int
    {
        return $this->intValue(self::KEY_DATABASE_USER_ID);
    }

    public function setDatabaseUserId(int $value): void
    {
        $this->saveValue(self::KEY_DATABASE_USER_ID, (string) $value);
    }

    /**
     * Blank every managed property in one save() (used after
     * TerminateAccount). Blanking rather than deleting: the WHMCS
     * serviceProperties API has no delete, and '' reads back as null here.
     */
    public function clear(): void
    {
        $this->properties->save(array_fill_keys(self::KEYS, ''));
    }

    private function saveValue(string $key, string $value): void
    {
        $this->properties->save([$key => $value]);
    }

    public function withFallbackReader(callable $reader): self
    {
        $this->fallbackReader = $reader(...);

        return $this;
    }

    /**
     * Re-saves every stored property through the model, so the service's
     * CURRENT product gains the custom-field definitions and values. Call
     * after a package change - the old product's field rows do not follow
     * the service.
     */
    public function resaveAll(): void
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $value = $this->stringValue($key);
            if ($value !== null) {
                $values[$key] = $value;
            }
        }
        if ($values !== []) {
            $this->properties->save($values);
        }
    }

    private function stringValue(string $key): ?string
    {
        $value = $this->properties->get($key);
        if (($value === null || $value === '') && $this->fallbackReader !== null) {
            $value = ($this->fallbackReader)($key);
        }
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function intValue(string $key): ?int
    {
        $value = $this->stringValue($key);
        if ($value !== null && is_numeric($value)) {
            return (int) $value;
        }

        // null, '', or corrupted non-numeric content: treat as not stored.
        return null;
    }
}
