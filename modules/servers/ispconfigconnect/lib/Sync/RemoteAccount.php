<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Sync;

/**
 * One ISPConfig client as WHMCS's ListAccounts / "Sync Accounts" feature wants
 * to see it (developers.whmcs.com/provisioning-modules/server-sync/).
 *
 * The panel-side identity (clientId, domainId, templateName) is carried
 * alongside the WHMCS payload so callers can log or link back to ISPConfig
 * without a second lookup; toWhmcsArray() emits ONLY the eight documented
 * keys.
 */
final readonly class RemoteAccount
{
    /**
     * WHMCS service status strings. The documented sample returns
     * `Status::ACTIVE` / `Status::SUSPENDED` (WHMCS\Service\Status), whose
     * values are the tblhosting.domainstatus vocabulary reproduced here - lib
     * code must not depend on WHMCS classes, and both mapping helpers take the
     * status strings as parameters so the entry file can pass the real
     * constants instead.
     */
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_SUSPENDED = 'Suspended';

    /**
     * @param string $product        the value that matches the product's
     *                               configoption1 (ListAccountsProductField):
     *                               the ISPConfig master template id, because
     *                               WHMCS stores the Loader array KEY, not its
     *                               display label
     * @param string $templateName   the template's display name, '' when the
     *                               template row no longer exists
     * @param string $created        'Y-m-d H:i:s', '' when ISPConfig never
     *                               stamped client.created_at
     */
    public function __construct(
        public int $clientId,
        public string $username,
        public string $email,
        public string $domain,
        public string $uniqueIdentifier,
        public string $product,
        public string $templateName,
        public string $primaryIp,
        public string $created,
        public bool $suspended,
        public ?int $domainId = null,
    ) {
    }

    /**
     * @return array{
     *     email: string, username: string, domain: string,
     *     uniqueIdentifier: string, product: string, primaryip: string,
     *     created: string, status: string
     * }
     */
    public function toWhmcsArray(
        string $activeStatus = self::STATUS_ACTIVE,
        string $suspendedStatus = self::STATUS_SUSPENDED,
    ): array {
        return [
            'email' => $this->email,
            'username' => $this->username,
            'domain' => $this->domain,
            'uniqueIdentifier' => $this->uniqueIdentifier,
            'product' => $this->product,
            'primaryip' => $this->primaryIp,
            'created' => $this->created,
            'status' => $this->suspended ? $suspendedStatus : $activeStatus,
        ];
    }
}
