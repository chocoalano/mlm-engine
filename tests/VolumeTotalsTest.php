<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PHPUnit\Framework\Attributes\DataProvider;

final class VolumeTotalsTest extends DatabaseTestCase
{
    use RecordsVolume;

    public function test_a_member_total_sums_its_entries_and_nets_reversals(): void
    {
        $member = Member::factory()->create();
        $this->record($member, '10', 'a');
        $twenty = $this->record($member, '20', 'b');
        $this->record($member, '5', 'c');

        $this->assertSame('35', $this->totals()->forMember($member, 'sales')->value());

        $this->reverse($twenty, 'refund:b');

        $this->assertSame('15', $this->totals()->forMember($member, 'sales')->value());
    }

    public function test_decimal_totals_are_exact(): void
    {
        $member = Member::factory()->create();

        foreach (['a', 'b', 'c'] as $key) {
            $this->record($member, '0.1', $key);
        }

        // 0.1 + 0.1 + 0.1 in floating point is 0.30000000000000004.
        $this->assertSame('0.3', $this->totals()->forMember($member, 'sales')->value());
    }

    public function test_a_total_counts_only_its_own_member_and_type(): void
    {
        $member = Member::factory()->create();
        $sameProgram = Member::factory()->for($member->program)->create();
        $otherProgram = Member::factory()->create();

        $this->record($member, '10', 'a');
        $this->record($member, '7', 'b', type: 'retail');
        $this->record($sameProgram, '100', 'c');
        $this->record($otherProgram, '1000', 'a');

        $this->assertSame('10', $this->totals()->forMember($member, 'sales')->value());
        $this->assertSame('7', $this->totals()->forMember($member, 'retail')->value());
        $this->assertSame('100', $this->totals()->forMember($sameProgram, 'sales')->value());
        $this->assertSame('1000', $this->totals()->forMember($otherProgram, 'sales')->value());
    }

    public function test_a_member_without_entries_totals_zero(): void
    {
        $this->assertSame('0', $this->totals()->forMember(Member::factory()->create(), 'sales')->value());
    }

    public function test_an_effective_range_includes_its_start_and_excludes_its_end(): void
    {
        $member = Member::factory()->create();
        $this->record($member, '1', 'before', at: CarbonImmutable::parse('2026-05-31 23:59:59'));
        $this->record($member, '10', 'start', at: CarbonImmutable::parse('2026-06-01 00:00:00'));
        $this->record($member, '100', 'inside', at: CarbonImmutable::parse('2026-06-15 12:00:00'));
        $this->record($member, '1000', 'end', at: CarbonImmutable::parse('2026-07-01 00:00:00'));

        $june = [CarbonImmutable::parse('2026-06-01 00:00:00'), CarbonImmutable::parse('2026-07-01 00:00:00')];

        $this->assertSame('110', $this->totals()->forMember($member, 'sales', ...$june)->value());
        $this->assertSame('1110', $this->totals()->forMember($member, 'sales', from: $june[0])->value());
        $this->assertSame('111', $this->totals()->forMember($member, 'sales', until: $june[1])->value());
    }

    public function test_a_reversal_counts_at_its_own_effective_moment(): void
    {
        $member = Member::factory()->create();
        $original = $this->record($member, '50', 'a', at: CarbonImmutable::parse('2026-06-10 00:00:00'));
        $this->reverse($original, 'r', at: CarbonImmutable::parse('2026-07-05 00:00:00'));

        $june = [CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-07-01')];
        $july = [CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-08-01')];

        $this->assertSame('50', $this->totals()->forMember($member, 'sales', ...$june)->value());
        $this->assertSame('-50', $this->totals()->forMember($member, 'sales', ...$july)->value());
        $this->assertSame('0', $this->totals()->forMember($member, 'sales')->value());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function emptyRanges(): array
    {
        return [
            'from equals until' => ['2026-06-01 00:00:00', '2026-06-01 00:00:00'],
            'from after until' => ['2026-07-01 00:00:00', '2026-06-01 00:00:00'],
            'equal to the second' => ['2026-06-01 00:00:00.200', '2026-06-01 00:00:00.900'],
        ];
    }

    #[DataProvider('emptyRanges')]
    public function test_an_empty_or_inverted_range_is_refused(string $from, string $until): void
    {
        $member = Member::factory()->create();
        $this->record($member, '10', 'a');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must start before it ends');

        $this->totals()->forMember($member, 'sales', CarbonImmutable::parse($from), CarbonImmutable::parse($until));
    }

    public function test_an_invalid_type_is_refused(): void
    {
        $this->expectException(InvalidVolumeEntry::class);

        $this->totals()->forMember(Member::factory()->create(), 'Sales');
    }
}
