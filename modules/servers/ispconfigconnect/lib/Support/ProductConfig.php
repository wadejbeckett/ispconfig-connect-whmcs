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
        // Option 13 (position frozen): checked = suspension locks the whole
        // ISPConfig client (locked='y' cascades to EVERY object -
        // functions.inc.php func_client_lock); unchecked = website-only
        // suspension via sites_web_domain_set_status, no client lock at all.
        // A 'lock but keep the website' middle ground does not exist.
        public bool $suspendEntireClient,
        public ?string $panelUrlOverride,
    ) {
    }
}
