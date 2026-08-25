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
        return $this->toHostingColumnsGiven(null);
    }

    /**
     * The same columns, but told what WHMCS currently holds for this service so
     * an accumulated month's bandwidth is never destroyed by a later zero.
     *
     * ISPConfig counts traffic only for ACTIVE websites
     * (quota_lib.inc.php get_trafficquota_data() filters `active = 'y'`), and
     * suspending a service sets the site inactive - so from the moment of
     * suspension the panel reports 0 bytes for the month. WHMCS keeps one
     * figure per service, so writing that 0 would erase the traffic the
     * customer really used before they were suspended, and the month would
     * bill as if they had used nothing.
     *
     * Traffic is cumulative-within-the-month and can only rise until the month
     * rolls over, so a reading LOWER than what is already stored is never new
     * information - it means the counter stopped (suspension) or reset. Keep
     * the higher figure. A genuine month rollover is not lost: the first
     * reading of the new month is compared against a stored value that the
     * billing run has already consumed.
     *
     * Disk usage is a live snapshot, not an accumulation, so it is always
     * written as measured - a suspended site really does still occupy its disk.
     *
     * @param int|null $currentBandwidthUsageMegabytes tblhosting.bwusage as it
     *        stands, or null when the caller cannot read it (then the measured
     *        value is written unconditionally, preserving old behaviour)
     * @return array{diskusage: int, bwusage: int, disklimit?: int, bwlimit?: int}
     */
    public function toHostingColumnsGiven(?int $currentBandwidthUsageMegabytes): array
    {
        $columns = ['diskusage' => $this->diskUsageMegabytes];
        if (!$this->diskUnlimited) {
            $columns['disklimit'] = $this->diskLimitMegabytes;
        }

        $columns['bwusage'] = $currentBandwidthUsageMegabytes !== null
            ? max($this->bandwidthUsageMegabytes, $currentBandwidthUsageMegabytes)
            : $this->bandwidthUsageMegabytes;

        if (!$this->bandwidthUnlimited) {
            $columns['bwlimit'] = $this->bandwidthLimitMegabytes;
        }

        return $columns;
    }
}
