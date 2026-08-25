<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Usage;

/**
 * Everything the module knows about one ISPConfig client's resource use,
 * normalised to BYTES and plain counts. Conversion to the megabytes WHMCS
 * stores happens in the accessors, so the raw figures stay auditable.
 *
 * A null *LimitBytes / *QuotaBytes means unlimited: ISPConfig's -1/0
 * sentinels are resolved once, here, instead of leaking into every consumer.
 */
final readonly class ClientUsage
{
    /**
     * @param list<int> $sysGroupIds every distinct sys_groupid seen in the
     *        client's records - more than one means the client's group list
     *        spanned several groups, i.e. a reseller id (see UsageParser and
     *        Collector's guard)
     */
    public function __construct(
        public int $clientId,
        public int $diskUsedBytes = 0,
        public ?int $diskLimitBytes = null,
        public int $trafficThisMonthBytes = 0,
        public ?int $trafficLimitBytes = null,
        public int $mailUsedBytes = 0,
        public ?int $mailQuotaBytes = null,
        public int $databaseUsedBytes = 0,
        public ?int $databaseQuotaBytes = null,
        public int $websiteCount = 0,
        public int $mailboxCount = 0,
        public int $databaseCount = 0,
        public int $ftpUserCount = 0,
        public int $dnsZoneCount = 0,
        public array $sysGroupIds = [],
        public ?int $sysGroupId = null,
    ) {
    }

    public function diskUsageMegabytes(): int
    {
        return QuotaMath::bytesToMegabytes($this->diskUsedBytes);
    }

    /** 0 when unlimited - WHMCS's tblhosting has no unlimited sentinel. */
    public function diskLimitMegabytes(): int
    {
        return QuotaMath::bytesToMegabytes($this->diskLimitBytes);
    }

    public function diskUnlimited(): bool
    {
        return $this->diskLimitBytes === null;
    }

    public function bandwidthUsageMegabytes(): int
    {
        return QuotaMath::bytesToMegabytes($this->trafficThisMonthBytes);
    }

    public function bandwidthLimitMegabytes(): int
    {
        return QuotaMath::bytesToMegabytes($this->trafficLimitBytes);
    }

    public function bandwidthUnlimited(): bool
    {
        return $this->trafficLimitBytes === null;
    }

    public function mailUsageMegabytes(): int
    {
        return QuotaMath::bytesToMegabytes($this->mailUsedBytes);
    }

    public function mailQuotaMegabytes(): int
    {
        return QuotaMath::bytesToMegabytes($this->mailQuotaBytes);
    }

    public function mailQuotaUnlimited(): bool
    {
        return $this->mailQuotaBytes === null;
    }

    public function databaseUsageMegabytes(): int
    {
        return QuotaMath::bytesToMegabytes($this->databaseUsedBytes);
    }

    public function databaseQuotaMegabytes(): int
    {
        return QuotaMath::bytesToMegabytes($this->databaseQuotaBytes);
    }

    public function databaseQuotaUnlimited(): bool
    {
        return $this->databaseQuotaBytes === null;
    }

    /**
     * The client's sys_groupid, resolved out of band (from
     * sys_user.default_group) when no record carried it.
     */
    public function withSysGroupId(?int $sysGroupId): self
    {
        return new self(
            clientId: $this->clientId,
            diskUsedBytes: $this->diskUsedBytes,
            diskLimitBytes: $this->diskLimitBytes,
            trafficThisMonthBytes: $this->trafficThisMonthBytes,
            trafficLimitBytes: $this->trafficLimitBytes,
            mailUsedBytes: $this->mailUsedBytes,
            mailQuotaBytes: $this->mailQuotaBytes,
            databaseUsedBytes: $this->databaseUsedBytes,
            databaseQuotaBytes: $this->databaseQuotaBytes,
            websiteCount: $this->websiteCount,
            mailboxCount: $this->mailboxCount,
            databaseCount: $this->databaseCount,
            ftpUserCount: $this->ftpUserCount,
            dnsZoneCount: $this->dnsZoneCount,
            sysGroupIds: $this->sysGroupIds,
            sysGroupId: $sysGroupId,
        );
    }

    /** FTP users and DNS zones need the group id, so they arrive separately. */
    public function withEntityCounts(int $ftpUserCount, int $dnsZoneCount): self
    {
        return new self(
            clientId: $this->clientId,
            diskUsedBytes: $this->diskUsedBytes,
            diskLimitBytes: $this->diskLimitBytes,
            trafficThisMonthBytes: $this->trafficThisMonthBytes,
            trafficLimitBytes: $this->trafficLimitBytes,
            mailUsedBytes: $this->mailUsedBytes,
            mailQuotaBytes: $this->mailQuotaBytes,
            databaseUsedBytes: $this->databaseUsedBytes,
            databaseQuotaBytes: $this->databaseQuotaBytes,
            websiteCount: $this->websiteCount,
            mailboxCount: $this->mailboxCount,
            databaseCount: $this->databaseCount,
            ftpUserCount: $ftpUserCount,
            dnsZoneCount: $dnsZoneCount,
            sysGroupIds: $this->sysGroupIds,
            sysGroupId: $this->sysGroupId,
        );
    }
}
