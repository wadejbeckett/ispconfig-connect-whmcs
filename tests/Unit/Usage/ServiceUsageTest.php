<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Usage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Usage\ServiceUsage;

/**
 * The bwusage month-reset semantics of ServiceUsage::toHostingColumnsGiven()
 * (H5 / review U1). tblhosting.bwusage is billed against a monthly overage
 * allowance, and ISPConfig's this_month restarts on its own on the 1st, so the
 * "keep the higher figure" rule that protects an accruing month from a
 * suspension zero MUST reset when the month rolls over - otherwise every
 * service freezes at the highest month it ever saw and is billed that forever.
 *
 * The clock is injected so a month boundary can be crossed deterministically.
 */
#[CoversClass(ServiceUsage::class)]
final class ServiceUsageTest extends TestCase
{
    private function usage(int $measuredBandwidthMegabytes): ServiceUsage
    {
        return new ServiceUsage(
            serviceId: 101,
            clientId: 7,
            diskUsageMegabytes: 100,
            diskLimitMegabytes: 0,
            diskUnlimited: true,
            bandwidthUsageMegabytes: $measuredBandwidthMegabytes,
            bandwidthLimitMegabytes: 0,
            bandwidthUnlimited: true,
        );
    }

    public function testAStoredPeakFromLastMonthIsNotCarriedIntoTheNewMonth(): void
    {
        // September peaked at 51200 MB; the first October reading is 1024 MB.
        // With the stored figure dated last month, October must bill 1024 - the
        // September peak has already been invoiced and must not ratchet forward.
        $columns = $this->usage(1024)->toHostingColumnsGiven(
            51200,
            '2026-09-30 23:50:00',
            new \DateTimeImmutable('2026-10-15 06:00:00')
        );

        self::assertSame(1024, $columns['bwusage']);
    }

    public function testAStoredPeakFromThisMonthStillWinsOverALowerReading(): void
    {
        // Same 51200 vs 1024, but the stored figure is dated THIS month: this is
        // the suspension / over-traffic-lock case, where a lower reading is not
        // new information and the accrued peak must survive.
        $columns = $this->usage(1024)->toHostingColumnsGiven(
            51200,
            '2026-10-05 12:00:00',
            new \DateTimeImmutable('2026-10-15 06:00:00')
        );

        self::assertSame(51200, $columns['bwusage']);
    }

    public function testAHigherCurrentMonthReadingReplacesTheStoredPeak(): void
    {
        $columns = $this->usage(60000)->toHostingColumnsGiven(
            51200,
            '2026-10-05 12:00:00',
            new \DateTimeImmutable('2026-10-15 06:00:00')
        );

        self::assertSame(60000, $columns['bwusage']);
    }

    public function testTheYearBoundaryIsAMonthRolloverToo(): void
    {
        // December 2025 -> January 2026: same absence of month match, so the
        // stored December peak must not carry into January.
        $columns = $this->usage(2048)->toHostingColumnsGiven(
            99999,
            '2025-12-31 23:00:00',
            new \DateTimeImmutable('2026-01-01 00:30:00')
        );

        self::assertSame(2048, $columns['bwusage']);
    }

    public function testTheSameMonthNumberInADifferentYearIsStillLastMonth(): void
    {
        // October 2025 stored, October 2026 now: the month NUMBER matches but
        // the year does not, so it is a rollover, not the current month.
        $columns = $this->usage(1024)->toHostingColumnsGiven(
            51200,
            '2025-10-05 12:00:00',
            new \DateTimeImmutable('2026-10-15 06:00:00')
        );

        self::assertSame(1024, $columns['bwusage']);
    }

    public function testANullStoredTimestampPreservesTheOldMaxBehaviour(): void
    {
        // Before the integrator wires lastupdate, storedLastUpdate arrives null;
        // the method must behave exactly as it did previously - max() - so
        // nothing regresses in the interim.
        $columns = $this->usage(1024)->toHostingColumnsGiven(51200, null, new \DateTimeImmutable('2026-10-15 06:00:00'));

        self::assertSame(51200, $columns['bwusage']);
    }

    public function testANullStoredBandwidthWritesTheMeasuredValueRegardlessOfDate(): void
    {
        // The caller could not read tblhosting.bwusage: the measured value is
        // written unconditionally, even with a current-month timestamp present.
        $columns = $this->usage(1024)->toHostingColumnsGiven(null, '2026-10-05 12:00:00', new \DateTimeImmutable('2026-10-15 06:00:00'));

        self::assertSame(1024, $columns['bwusage']);
    }

    public function testABlankOrZeroTimestampIsTreatedAsNotCurrentMonth(): void
    {
        $now = new \DateTimeImmutable('2026-10-15 06:00:00');

        $blank = $this->usage(1024)->toHostingColumnsGiven(51200, '   ', $now);
        self::assertSame(1024, $blank['bwusage'], 'a blank timestamp cannot be trusted as this month');

        $zeroDate = $this->usage(1024)->toHostingColumnsGiven(51200, '0000-00-00 00:00:00', $now);
        self::assertSame(1024, $zeroDate['bwusage'], 'the MySQL zero date is not this month');
    }

    public function testAnUnparseableTimestampFallsBackToTheMeasuredValue(): void
    {
        $columns = $this->usage(1024)->toHostingColumnsGiven(
            51200,
            'not a timestamp at all',
            new \DateTimeImmutable('2026-10-15 06:00:00')
        );

        self::assertSame(1024, $columns['bwusage']);
    }

    public function testTheDefaultClockUsesTheCurrentMonth(): void
    {
        // With no injected clock, a timestamp in the real current month keeps
        // the higher stored figure (exercises the default "now" path).
        $thisMonth = date('Y-m-15 12:00:00');

        $columns = $this->usage(1024)->toHostingColumnsGiven(51200, $thisMonth);

        self::assertSame(51200, $columns['bwusage']);
    }

    public function testDiskUsageIsAlwaysTheLiveSnapshotAndTheColumnNamesAreUnchanged(): void
    {
        $columns = $this->usage(1024)->toHostingColumnsGiven(51200, '2026-09-30 23:50:00', new \DateTimeImmutable('2026-10-15 06:00:00'));

        self::assertSame(['diskusage' => 100, 'bwusage' => 1024], $columns);
    }
}
