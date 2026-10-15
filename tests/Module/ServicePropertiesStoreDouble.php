<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

/**
 * Stands in for $params['model']->serviceProperties: the WHMCS per-service
 * key/value store with the get(string)/save(array) API that
 * Support\ServiceProperties duck-types against.
 */
final class ServicePropertiesStoreDouble
{
    /**
     * When set, every save() throws a RuntimeException with this message -
     * simulates the WHMCS property store failing AFTER provisioning
     * succeeded (the orphan-reconciliation path of CreateAccount).
     */
    public ?string $throwOnSaveMessage = null;

    /** @param array<string, string> $values */
    public function __construct(private array $values = [])
    {
    }

    public function get(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    /** @param array<string, string> $values */
    public function save(array $values): void
    {
        if ($this->throwOnSaveMessage !== null) {
            throw new \RuntimeException($this->throwOnSaveMessage);
        }
        $this->values = array_merge($this->values, $values);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->values;
    }
}
