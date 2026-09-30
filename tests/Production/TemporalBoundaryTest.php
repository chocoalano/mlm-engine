<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Production;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Period\CommissionPeriodCalculator;
use PandaBear\Mlm\Tests\DatabaseTestCase;
use PandaBear\Mlm\Tests\Panel\Concerns\BuildsOperations;
use PandaBear\Mlm\Volume\VolumeTotals;

/**
 * Every range is [from, until): an entry at `from` counts, one a second
 * before `until` counts, one at `until` belongs to the next range. And an
 * instant is an instant, whatever timezone it is written in.
 */
final class TemporalBoundaryTest extends DatabaseTestCase
{
    use BuildsOperations;

    public function test_volume_totals_count_from_inclusive_and_until_exclusive_in_any_timezone(): void
    {
        $this->operatingProgram('BOB');
        $bob = $this->team['BOB'];

        $this->sale($bob, '1', '2026-01-01 00:00:00', 'order:at-from');
        $this->sale($bob, '10', '2026-01-31 23:59:59', 'order:before-until');
        $this->sale($bob, '100', '2026-02-01 00:00:00', 'order:at-until');

        $totals = $this->app->make(VolumeTotals::class);
        $january = $totals->forMember($bob, 'sales', CarbonImmutable::parse('2026-01-01 00:00:00'), CarbonImmutable::parse('2026-02-01 00:00:00'));
        $this->assertSame('11', $january->value());

        // The same two instants written in Jakarta time: seven hours ahead.
        $jakarta = new DateTimeZone('Asia/Jakarta');
        $this->assertSame('11', $totals->forMember($bob, 'sales', new DateTimeImmutable('2026-01-01 07:00:00', $jakarta), new DateTimeImmutable('2026-02-01 07:00:00', $jakarta))->value());

        // Consecutive ranges never count an entry twice.
        $february = $totals->forMember($bob, 'sales', CarbonImmutable::parse('2026-02-01 00:00:00'), CarbonImmutable::parse('2026-03-01 00:00:00'));
        $this->assertSame('100', $february->value());
    }

    public function test_a_period_calculates_its_range_from_inclusive_and_until_exclusive(): void
    {
        $this->operatingProgram('BOB', 'CAROL', 'DAVE');
        $version = $this->activeVersion();

        $this->sale($this->team['BOB'], '150', '2026-01-01 00:00:00', 'order:at-from');
        $this->sale($this->team['CAROL'], '150', '2026-01-31 23:59:59', 'order:before-until');
        $this->sale($this->team['DAVE'], '150', '2026-02-01 00:00:00', 'order:at-until');

        $this->app->make(CommissionPeriodCalculator::class)->calculate($this->openPeriod($version));

        $this->assertSame(
            ['ORDER:AT-FROM', 'ORDER:BEFORE-UNTIL'],
            Commission::query()->with('run')->get()->map(fn (Commission $commission): string => VolumeEntry::query()->findOrFail($commission->source_id)->source_id)->sort()->values()->all(),
        );
    }
}
