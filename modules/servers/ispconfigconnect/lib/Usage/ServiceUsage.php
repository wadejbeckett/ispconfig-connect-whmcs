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
     * an accumulated month's bandwidth is never destroyed by a later zero -
     * AND told how fresh that stored figure is, so last month's peak is never
     * carried forward into the new month.
     *
     * ISPConfig counts traffic only for ACTIVE websites
     * (quota_lib.inc.php get_trafficquota_data() filters `active = 'y'`), and
     * suspending a service sets the site inactive - so from the moment of
     * suspension the panel reports 0 bytes for the month. WHMCS keeps one
     * figure per service, so writing that 0 would erase the traffic the
     * customer really used before they were suspended, and the month would
     * bill as if they had used nothing.
     *
     * Within one calendar month traffic is cumulative and can only rise, so a
     * reading LOWER than what is already stored is not new information - it
     * means the counter stopped (suspension, or ISPConfig's own over-traffic
     * lock) - and the higher figure is kept.
     *
     * That rule MUST reset at the month boundary. ISPConfig's `this_month`
     * restarts on its own on the 1st (its SQL filters YEAR()/MONTH() against
     * the current date), so the first reading of a new month is genuinely
     * smaller than the peak of the month just ended. Keeping the max() across
     * that boundary would freeze every service at "the highest month ever
     * seen" and bill that figure forever. So the stored value is only kept
     * when it is CURRENT-MONTH: $storedLastUpdate carries WHMCS's `lastupdate`
     * for the row (stamped now() on every write), and once it falls in an
     * earlier calendar month the freshly measured value - this month's - is
     * authoritative and replaces it.
     *
     * When $storedLastUpdate is null the freshness is unknown, so the previous
     * behaviour is preserved: max() against the stored figure. This keeps the
     * method safe before the integrator wires `lastupdate` in from the entry
     * file; the month never resets, but nothing that worked before breaks.
     *
     * Disk usage is a live snapshot, not an accumulation, so it is always
     * written as measured - a suspended site really does still occupy its disk.
     *
     * @param int|null $currentBandwidthUsageMegabytes tblhosting.bwusage as it
     *        stands, or null when the caller cannot read it (then the measured
     *        value is written unconditionally, preserving old behaviour)
     * @param string|null $storedLastUpdate tblhosting.lastupdate for the stored
     *        bwusage, e.g. '2026-09-24 12:00:00' (server localtime), or null
     *        when the caller cannot date it - see the note above on why null
     *        keeps the old max() behaviour
     * @param \DateTimeImmutable|null $now injectable clock for "the current
     *        calendar month"; defaults to now in the server's localtime, the
     *        same zone WHMCS writes lastupdate in
     * @return array{diskusage: int, bwusage: int, disklimit?: int, bwlimit?: int}
     */
    public function toHostingColumnsGiven(
        ?int $currentBandwidthUsageMegabytes,
        ?string $storedLastUpdate = null,
        ?\DateTimeImmutable $now = null,
    ): array {
        $columns = ['diskusage' => $this->diskUsageMegabytes];
        if (!$this->diskUnlimited) {
            $columns['disklimit'] = $this->diskLimitMegabytes;
        }

        $columns['bwusage'] = $this->resolveBandwidthUsage(
            $currentBandwidthUsageMegabytes,
            $storedLastUpdate,
            $now
        );

        if (!$this->bandwidthUnlimited) {
            $columns['bwlimit'] = $this->bandwidthLimitMegabytes;
        }

        return $columns;
    }

    /**
     * The bwusage figure to write: the higher of measured and stored while the
     * stored figure belongs to the current calendar month, else the measured
     * one. See toHostingColumnsGiven() for the reasoning.
     */
    private function resolveBandwidthUsage(
        ?int $currentBandwidthUsageMegabytes,
        ?string $storedLastUpdate,
        ?\DateTimeImmutable $now,
    ): int {
        if ($currentBandwidthUsageMegabytes === null) {
            // The caller cannot read tblhosting.bwusage: write the measured
            // value unconditionally, preserving the pre-freshness behaviour.
            return $this->bandwidthUsageMegabytes;
        }

        if ($storedLastUpdate !== null
            && !self::isCurrentMonth($storedLastUpdate, $now ?? new \DateTimeImmutable('now'))
        ) {
            // The month rolled over: the stored peak is last month's, already
            // billed, and must not carry forward. This month's reading wins.
            return $this->bandwidthUsageMegabytes;
        }

        // Current month, or freshness unknown (null): keep the higher figure so
        // a suspension / over-traffic lock cannot erase the month's accrual.
        return max($this->bandwidthUsageMegabytes, $currentBandwidthUsageMegabytes);
    }

    /**
     * Whether a WHMCS `lastupdate` timestamp string falls in the same calendar
     * month as $now, both in the server's localtime. A blank, zero
     * ('0000-00-00 00:00:00') or otherwise unparseable value is treated as NOT
     * current-month, so the measured value is used - the safe choice, since an
     * undatable stored figure cannot be trusted to be this month's.
     */
    private static function isCurrentMonth(string $storedLastUpdate, \DateTimeImmutable $now): bool
    {
        $trimmed = trim($storedLastUpdate);
        if ($trimmed === '' || str_starts_with($trimmed, '0000-00-00')) {
            return false;
        }

        try {
            $stored = new \DateTimeImmutable($trimmed);
        } catch (\Exception) {
            return false;
        }

        return $stored->format('Y-m') === $now->format('Y-m');
    }
}
