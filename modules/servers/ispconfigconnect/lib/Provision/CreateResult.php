<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Provision;

/**
 * Every ISPConfig id produced by Recipe::create(), plus the panel username.
 * Null means the corresponding stage was disabled in the product config.
 * The entry file persists these one-to-one into ServiceProperties
 * (ispconfig_client_id, ispconfig_domain_id, ... - spec 2.5/2.8).
 */
final readonly class CreateResult
{
    public function __construct(
        public int $clientId,
        public string $username,
        public ?int $domainId = null,
        public ?int $ftpUserId = null,
        public ?int $mailDomainId = null,
        public ?int $dnsZoneId = null,
        public ?int $databaseUserId = null,
        public ?int $databaseId = null,
    ) {
    }
}
