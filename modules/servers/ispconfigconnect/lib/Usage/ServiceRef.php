<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Usage;

/**
 * One provisioned WHMCS service, as the entry file knows it.
 *
 * $username is the ISPConfig client username the module stored in the
 * ispconfig_client_username service property, which is also the value WHMCS
 * keeps in tblhosting.username - so it doubles as the TENANT KEY for usage
 * metrics (developers.whmcs.com/provisioning-modules/usage-metrics/ keys
 * usage() by the account username) and as the lookup argument for
 * client_get_by_username when a client's sys_groupid has to be resolved.
 */
final readonly class ServiceRef
{
    public function __construct(
        public int $serviceId,
        public int $clientId,
        public string $username = '',
    ) {
    }
}
