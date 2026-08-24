<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

/**
 * Stands in for $params['model'] (the WHMCS service Eloquent model): exposes
 * the serviceProperties accessor plus the username attribute the entry file
 * writes back when it generates a username for an empty service.
 */
final class ServiceModelDouble
{
    public ServicePropertiesStoreDouble $serviceProperties;

    public ?string $username = null;

    public int $saveCalls = 0;

    public function __construct(?ServicePropertiesStoreDouble $properties = null)
    {
        $this->serviceProperties = $properties ?? new ServicePropertiesStoreDouble();
    }

    public function save(): bool
    {
        $this->saveCalls++;

        return true;
    }
}
