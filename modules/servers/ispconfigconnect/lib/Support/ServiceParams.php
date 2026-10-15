<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Support;

/**
 * One WHMCS service (hosting account) as passed to a provisioning entry point,
 * parsed from the raw $params array by Params::service().
 */
final readonly class ServiceParams
{
    /**
     * @param array{
     *     name: string,
     *     company: string,
     *     email: string,
     *     address1: string,
     *     city: string,
     *     state: string,
     *     postcode: string,
     *     country: string,
     *     phone: string
     * } $clientDetails contact fields from $params['clientsdetails'];
     *     country is the ISO-3166 two-letter code WHMCS stores
     * @param array<string, mixed> $configOptions raw WHMCS configurable
     *     options (name => selected value)
     */
    public function __construct(
        public int $serviceId,
        public string $domain,
        public string $username,
        #[\SensitiveParameter]
        public string $password,
        public array $clientDetails,
        public int $whmcsClientId,
        public string $serverIp,
        public string $language,
        public array $configOptions,
    ) {
    }
}
