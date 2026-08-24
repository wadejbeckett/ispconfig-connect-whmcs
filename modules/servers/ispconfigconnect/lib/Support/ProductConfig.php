<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Support;

/**
 * Product-level module settings, parsed from configoption1..configoption14 by
 * Params::productConfig(). The option order is frozen (IMPLEMENTATION-NOTES
 * §3.1) — WHMCS stores values by position, so renumbering after ship would
 * silently reassign every existing product's settings.
 *
 * clientTemplateId / webServerId of 0 mean "not selected".
 */
final readonly class ProductConfig
{
    public function __construct(
        public int $clientTemplateId,
        public int $webServerId,
        public bool $createWebsite,
        public bool $createFtpUser,
        public bool $createMailDomain,
        public bool $createDnsZone,
        public int $dnsTemplateId,
        public string $ns1,
        public string $ns2,
        public string $dnsAdminEmail,
        public bool $createDatabase,
        public string $phpMode,
        public bool $suspendAlsoDisablesWebsite,
        public ?string $panelUrlOverride,
    ) {
    }
}
