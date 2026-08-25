<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Usage;

/**
 * One service's disk and bandwidth figures in the units WHMCS stores.
 *
 * tblhosting.diskusage/disklimit/bwusage/bwlimit are all MEGABYTES - see the
 * UpdateClientProduct API reference, which documents the same four columns
 * UsageUpdate writes (developers.whmcs.com/api-reference/updateclientproduct/).
 *
 * WHMCS has no "unlimited" sentinel for those columns: disklimit/bwlimit are
 * ADMIN-CONFIGURED values (product setup or a manual edit) that drive overage
 * billing and the client-area usage bars. An unlimited ISPConfig quota is the
 * NORMAL provisioned state (Provision\Mapper sends hd_quota = -1 and
 * traffic_quota = -1 on every website), so it is reported as limit 0 with the
 * *Unlimited flag set - and toHostingColumns() OMITS the limit columns
 * entirely in that case, so the nightly sweep never overwrites a configured
 * WHMCS limit with a silent 0.
 */
final readonly class ServiceUsage
{
    public function __construct(
        public int $serviceId,
        public int $clientId,
        public int $diskUsageMegabytes,
        public int $diskLimitMegabytes,
        public bool $diskUnlimited,
        public int $bandwidthUsageMegabytes,
        public int $bandwidthLimitMegabytes,
        public bool $bandwidthUnlimited,
    ) {
    }

    public static function fromClientUsage(int $serviceId, ClientUsage $usage): self
    {
        return new self(
            serviceId: $serviceId,
            clientId: $usage->clientId,
            diskUsageMegabytes: $usage->diskUsageMegabytes(),
            diskLimitMegabytes: $usage->diskLimitMegabytes(),
            diskUnlimited: $usage->diskUnlimited(),
            bandwidthUsageMegabytes: $usage->bandwidthUsageMegabytes(),
            bandwidthLimitMegabytes: $usage->bandwidthLimitMegabytes(),
            bandwidthUnlimited: $usage->bandwidthUnlimited(),
        );
    }

    /**
     * The tblhosting columns UsageUpdate updates, ready to hand to Capsule.
     *
     * An UNLIMITED ISPConfig quota omits its limit column instead of writing
     * 0: disklimit/bwlimit belong to the WHMCS admin (product config), and the
     * module provisions every website unlimited on the panel side, so writing
     * the resolved 0 would zero every service's configured limit nightly -
     * disabling overage billing. The usage columns are always written.
     *
     * @return array{diskusage: int, bwusage: int, disklimit?: int, bwlimit?: int}
     */
    public function toHostingColumns(): array
    {
        $columns = ['diskusage' => $this->diskUsageMegabytes];
        if (!$this->diskUnlimited) {
            $columns['disklimit'] = $this->diskLimitMegabytes;
        }
        $columns['bwusage'] = $this->bandwidthUsageMegabytes;
        if (!$this->bandwidthUnlimited) {
            $columns['bwlimit'] = $this->bandwidthLimitMegabytes;
        }

        return $columns;
    }
}
