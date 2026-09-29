<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Binary\Pairing\BinaryPairingFixedStrategy;
use PandaBear\Mlm\Binary\Pairing\BinaryPairingProportionalStrategy;
use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionComponentDriver;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Exceptions\InvalidBinaryPairingRange;
use PandaBear\Mlm\Exceptions\InvalidBinaryPairingState;
use PandaBear\Mlm\Exceptions\InvalidCommissionCandidate;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Models\BinaryCarryLot;
use PandaBear\Mlm\Models\BinaryPairingAllocation;
use PandaBear\Mlm\Models\BinaryPairingCursor;
use PandaBear\Mlm\Models\BinaryPairingResult;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Planning\PlanDefinitionValidator;
use PandaBear\Mlm\Tests\Concerns\BuildsBinaryPairing;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsFixedCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `binary.pairing.fixed` and `binary.pairing.proportional` (ADR-023): each
 * run takes in its range's volume as carry lots in every binary leg it fell
 * in then, pairs once at its close — equal quantities from each leg, the
 * oldest sources first — and keeps what is left for the component's next
 * run.
 */
final class BinaryPairingStrategyTest extends DatabaseTestCase
{
    use BuildsBinaryPairing;
    use BuildsCommissions;
    use BuildsFixedCommissions;
    use BuildsGenealogies;
    use BuildsLedgers;
    use BuildsPlanDefinitions;
    use RecordsVolume;

    private Plan $plan;

    /**
     * @var array<string, Member>
     */
    private array $members;

    protected function setUp(): void
    {
        parent::setUp();

        // P > L left > L2 left; P > R right > R2 right; X placed under L
        // generically only. All from January 1.
        $this->plan = Plan::factory()->create();
        $this->members = $this->members($this->plan->program, 'P', 'L', 'R', 'L2', 'R2', 'X');
        $this->binaryAt($this->members, 'P', 'L', BinarySide::Left);
        $this->binaryAt($this->members, 'P', 'R', BinarySide::Right);
        $this->binaryAt($this->members, 'L', 'L2', BinarySide::Left);
        $this->binaryAt($this->members, 'R', 'R2', BinarySide::Right);
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $this->placement()->place($this->members['X'], $this->members['L']);
        $this->travelBack();
    }

    public function test_pairs_form_at_the_run_close_and_what_is_left_carries_to_the_next_run(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['L'], '250', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '120', '2026-01-11', 'r1');

        $january = $this->pair($component, '2026-01-01', '2026-02-01');

        $this->assertSame(['P' => [
            'left' => '0 + 250 - 0 = 250 -> 150',
            'right' => '0 + 120 - 0 = 120 -> 20',
            'pairs' => '1 x 100 = 100',
            'commission' => true,
        ]], $this->pairingResults($january));

        $this->sale($this->members['R'], '180', '2026-02-11', 'r2');
        $february = $this->pair($component, '2026-02-01', '2026-03-01');

        $this->assertSame(['P' => [
            'left' => '150 + 0 - 0 = 150 -> 50',
            'right' => '20 + 180 - 0 = 200 -> 100',
            'pairs' => '1 x 100 = 100',
            'commission' => true,
        ]], $this->pairingResults($february));
        $this->assertSame(['P left l1' => '250/50', 'P right r1' => '120/0', 'P right r2' => '180/100'], $this->carryLots($component));
    }

    public function test_one_sided_carry_waits_for_the_other_leg(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['L'], '250', '2026-01-10', 'l1');

        $january = $this->pair($component, '2026-01-01', '2026-02-01');

        $this->assertSame(['P' => ['left' => '0 + 250 - 0 = 250 -> 250', 'right' => '0 + 0 - 0 = 0 -> 0', 'pairs' => '0 x 100 = 0', 'commission' => false]], $this->pairingResults($january));
        $this->assertSame(0, $january->commissions()->count());

        $this->sale($this->members['R'], '100', '2026-02-11', 'r1');

        $this->assertSame('1 x 100 = 100', $this->pairingResults($this->pair($component, '2026-02-01', '2026-03-01'))['P']['pairs']);
    }

    public function test_several_pairs_form_in_one_run_and_each_leg_gives_up_the_same_quantity(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['L'], '350', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '270', '2026-01-11', 'r1');

        $run = $this->pair($component, '2026-01-01', '2026-02-01');

        $this->assertSame(['left' => '0 + 350 - 0 = 350 -> 150', 'right' => '0 + 270 - 0 = 270 -> 70', 'pairs' => '2 x 100 = 200', 'commission' => true], $this->pairingResults($run)['P']);
        $this->assertSame('20', $run->commissions()->sole()->amount->value());
        $this->assertEqualsCanonicalizing(['P left l1 200', 'P right r1 200'], $this->allocationsOf($run));
    }

    public function test_a_fractional_pair_quantity_pairs_exactly(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(['pair_quantity' => '2.5']), $this->plan);
        $this->sale($this->members['L'], '10.1', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '7.6', '2026-01-11', 'r1');

        $run = $this->pair($component, '2026-01-01', '2026-02-01');

        $this->assertSame(['left' => '0 + 10.1 - 0 = 10.1 -> 2.6', 'right' => '0 + 7.6 - 0 = 7.6 -> 0.1', 'pairs' => '3 x 2.5 = 7.5', 'commission' => true], $this->pairingResults($run)['P']);
        $this->assertSame('30', $run->commissions()->sole()->amount->value());
    }

    public function test_an_entry_feeds_every_binary_ancestor_in_the_leg_it_fell_in(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['L2'], '300', '2026-01-10', 'l2');
        $this->sale($this->members['R2'], '100', '2026-01-10', 'r2');

        $run = $this->pair($component, '2026-01-01', '2026-02-01');

        $this->assertSame(['L left l2' => '300/300', 'P left l2' => '300/200', 'P right r2' => '100/0', 'R right r2' => '100/100'], $this->carryLots($component));
        $this->assertSame(['L', 'P', 'R'], array_keys($this->pairingResults($run)));
        $this->assertSame(['binary-pairing:'.$this->members['P']->id], $run->commissions()->pluck('candidate_key')->all());
    }

    public function test_a_members_own_activity_and_other_types_never_enter_its_legs(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['P'], '1000', '2026-01-10', 'own');
        $this->sale($this->members['L'], '100', '2026-01-10', 'l-bonus', type: 'bonus_points');
        $this->sale($this->members['L'], '100', '2026-01-10', 'l1');

        $this->pair($component, '2026-01-01', '2026-02-01');

        $this->assertSame(['P left l1' => '100/100'], $this->carryLots($component));
    }

    public function test_only_entries_whose_moment_is_in_the_range_are_taken_in(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['L'], '100', '2025-12-31 23:59:59', 'before');
        $this->record($this->members['L'], '100', 'start', at: CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->record($this->members['L'], '100', 'end', at: CarbonImmutable::parse('2026-02-01 00:00:00'));

        $this->pair($component, '2026-01-01', '2026-02-01');

        $this->assertSame(['P left start' => '100/100'], $this->carryLots($component));

        $this->pair($component, '2026-02-01', '2026-03-01');

        $this->assertSame(['P left end' => '100/100', 'P left start' => '100/100'], $this->carryLots($component));
    }

    public function test_a_generic_only_member_feeds_no_carry_even_once_adopted_later(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['X'], '500', '2026-01-10', 'x-jan');
        $this->pair($component, '2026-01-01', '2026-02-01');

        $this->travelTo(CarbonImmutable::parse('2026-03-01'));
        $this->binary()->adopt(PlacementEdge::query()->where('member_id', $this->members['X']->id)->sole(), BinarySide::Right);
        $this->travelBack();
        $this->sale($this->members['X'], '7', '2026-03-10', 'x-mar');
        $this->pair($component, '2026-02-01', '2026-03-01');
        $this->pair($component, '2026-03-01', '2026-04-01');

        // Only March's sale, in the legs X joined in March.
        $this->assertSame(['L right x-mar' => '7/7', 'P left x-mar' => '7/7'], $this->carryLots($component));
    }

    public function test_the_tree_as_it_stood_at_each_activity_decides_even_when_calculated_later(): void
    {
        // January 10: X, placed only generically under L, sells. January 20:
        // X is adopted on L's right. January 25: X sells again. January is
        // calculated afterwards, with X in the binary tree.
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['X'], '500', '2026-01-10', 'x-before');
        $this->travelTo(CarbonImmutable::parse('2026-01-20'));
        $this->binary()->adopt(PlacementEdge::query()->where('member_id', $this->members['X']->id)->sole(), BinarySide::Right);
        $this->travelBack();
        $this->sale($this->members['X'], '7', '2026-01-25', 'x-after');

        $this->pair($component, '2026-01-01', '2026-02-01');

        $this->assertSame(['L right x-after' => '7/7', 'P left x-after' => '7/7'], $this->carryLots($component));
    }

    public function test_carry_is_consumed_oldest_source_first_and_every_draw_is_recorded(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $late = $this->sale($this->members['L'], '60', '2026-01-05', 'late-a');
        $this->sale($this->members['L'], '50', '2026-01-03', 'early');
        $tie = $this->sale($this->members['L'], '40', '2026-01-05', 'late-b');
        $this->pair($component, '2026-01-01', '2026-02-01');

        $this->sale($this->members['R'], '120', '2026-02-10', 'r1');
        $february = $this->pair($component, '2026-02-01', '2026-03-01');

        // Oldest moment first; within one moment, by entry id.
        [$first, $second] = strcmp($late->id, $tie->id) < 0 ? ['late-a', 'late-b'] : ['late-b', 'late-a'];
        $this->assertSame([
            'P left early' => '50/0',
            "P left {$first}" => ($first === 'late-a' ? '60/10' : '40/0'),
            "P left {$second}" => ($first === 'late-a' ? '40/40' : '60/60'),
            'P right r1' => '120/20',
        ], array_intersect_key($this->carryLots($component), array_flip(['P left early', "P left {$first}", "P left {$second}", 'P right r1'])));
        $this->assertEqualsCanonicalizing(
            ['P left early 50', 'P left '.$first.' '.($first === 'late-a' ? '50' : '40'), ...($first === 'late-a' ? [] : ['P left late-a 10']), 'P right r1 100'],
            $this->allocationsOf($february),
        );
    }

    public function test_an_entry_leads_through_its_lot_and_allocation_to_the_commission_it_paid(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $run = $this->pair($component, '2026-01-01', '2026-02-01');

        $lot = BinaryCarryLot::query()->where('source_volume_entry_id', $sale->id)->sole();
        $allocation = $lot->allocations()->sole();
        $result = $allocation->result;

        $this->assertTrue($lot->sourceEntry->is($sale));
        $this->assertTrue($lot->member->is($this->members['P']));
        $this->assertSame([BinarySide::Left, '100', '0'], [$lot->side, $lot->quantity->value(), $lot->remaining->value()]);
        $this->assertSame([BinarySide::Left, '100'], [$allocation->side, $allocation->quantity->value()]);
        $this->assertTrue($allocation->carryLot->is($lot));
        $this->assertTrue($result->run->is($run));
        $this->assertTrue($result->member->is($this->members['P']));
        $this->assertTrue($result->commission?->is($run->commissions()->sole()));
        $this->assertCount(2, $result->allocations);
        $this->assertSame([$result->id], $run->binaryPairingResults()->pluck('id')->all());
    }

    public function test_a_fixed_award_is_a_whole_multiple_earned_at_the_runs_close_without_a_single_source(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(['amount_per_pair' => '12.5']), $this->plan);
        $this->sale($this->members['L'], '330', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '300', '2026-01-10', 'r1');

        $commission = $this->pair($component, '2026-01-01', '2026-02-01')->commissions()->sole();

        $this->assertSame(['binary-pairing:'.$this->members['P']->id, '37.5', '2026-02-01 00:00:00'], [$commission->candidate_key, $commission->amount->value(), $commission->earned_at->format('Y-m-d H:i:s')]);
        $this->assertSame([null, null, 'calculated'], [$commission->source_type, $commission->source_id, $commission->status->value]);
        $this->assertSame([
            'binary_member_id' => $this->members['P']->id,
            'calculation' => ['amount' => '37.5', 'amount_per_pair' => '12.5'],
            'carry' => [
                'left_added' => '330', 'left_after' => '30', 'left_before' => '0', 'left_restored' => '0', 'left_reversed' => '0',
                'right_added' => '300', 'right_after' => '0', 'right_before' => '0', 'right_restored' => '0', 'right_reversed' => '0',
            ],
            'pairing' => ['consumed_quantity' => '300', 'pair_count' => '3', 'pair_quantity' => '100'],
            'run' => ['from' => '2026-01-01 00:00:00', 'until' => '2026-02-01 00:00:00'],
            'strategy' => 'binary.pairing.fixed',
        ], $commission->trace);
    }

    public function test_a_fixed_award_larger_than_one_posting_fails_the_run_and_moves_no_carry(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(['amount_per_pair' => '9223372036854.775807']), $this->plan);
        $this->sale($this->members['L'], '250', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '50', '2026-01-10', 'r1');
        $this->pair($component, '2026-01-01', '2026-02-01');
        $this->sale($this->members['R'], '200', '2026-02-10', 'r2');
        $state = $this->pairingState();

        try {
            $this->pair($component, '2026-02-01', '2026-03-01');
            $this->fail('Two pairs worth more than one posting were accepted.');
        } catch (InvalidCommissionCandidate $exception) {
            $this->assertStringContainsString('one ledger posting, which holds at most 9223372036854.775807', $exception->getMessage());
        }

        $this->assertEquals($state, $this->pairingState());
        $this->assertSame('2026-02-01 00:00:00', BinaryPairingCursor::query()->sole()->through_at->format('Y-m-d H:i:s'));
    }

    public function test_a_proportional_award_is_the_consumed_quantity_times_the_unit_amount_rounded_once(): void
    {
        $component = $this->pairingComponent($this->proportionalPairing(['pair_quantity' => '0.5', 'unit_amount' => '0.123457']), $this->plan, 'binary.pairing.proportional');
        $this->sale($this->members['L'], '250.5', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '300', '2026-01-10', 'r1');

        $run = $this->pair($component, '2026-01-01', '2026-02-01');
        $commission = $run->commissions()->sole();

        $this->assertSame('501 x 0.5 = 250.5', $this->pairingResults($run)['P']['pairs']);
        $this->assertSame('30.925978', $commission->amount->value());
        $this->assertSame(
            ['amount' => '30.925978', 'exact_amount' => '30.9259785', 'quantity' => '250.5', 'rounded' => true, 'rounding' => 'half_even', 'unit_amount' => '0.123457'],
            $commission->trace['calculation'],
        );
        $this->assertSame('binary.pairing.proportional', $commission->trace['strategy']);
    }

    public function test_pairs_whose_proportional_award_rounds_to_nothing_still_consume_carry(): void
    {
        $component = $this->pairingComponent($this->proportionalPairing(['pair_quantity' => '0.1', 'unit_amount' => '0.000001']), $this->plan, 'binary.pairing.proportional');
        $this->sale($this->members['L'], '0.4', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '0.4', '2026-01-10', 'r1');

        $run = $this->pair($component, '2026-01-01', '2026-02-01');

        $this->assertSame(0, $run->commissions()->count());
        $this->assertSame(['left' => '0 + 0.4 - 0 = 0.4 -> 0', 'right' => '0 + 0.4 - 0 = 0.4 -> 0', 'pairs' => '4 x 0.1 = 0.4', 'commission' => false], $this->pairingResults($run)['P']);
        $this->assertEqualsCanonicalizing(['P left l1 0.4', 'P right r1 0.4'], $this->allocationsOf($run));
        $this->assertSame(['P left l1' => '0.4/0', 'P right r1' => '0.4/0'], $this->carryLots($component));
    }

    public function test_pair_counts_and_consumed_quantities_beyond_64_bits_stay_exact(): void
    {
        $component = $this->pairingComponent($this->proportionalPairing(['pair_quantity' => '0.000001', 'unit_amount' => '0.000001']), $this->plan, 'binary.pairing.proportional');

        // Ten of the largest entries on each side: more millionths, and more
        // pairs, than a 64-bit integer holds.
        foreach (range(1, 10) as $i) {
            $this->sale($this->members['L'], '999999999999.999999', '2026-01-10', "l-{$i}");
            $this->sale($this->members['R'], '999999999999.999999', '2026-01-10', "r-{$i}");
        }

        $run = $this->pair($component, '2026-01-01', '2026-02-01');

        $this->assertSame('9999999999999999990 x 0.000001 = 9999999999999.99999', $this->pairingResults($run)['P']['pairs']);
        $this->assertSame('10000000', $run->commissions()->sole()->amount->value());
        $this->assertSame('9999999999999.99999', BinaryPairingResult::query()->sole()->left_available);
    }

    public function test_carry_beyond_64_bits_is_carried_exactly(): void
    {
        // Summed here, lot by lot, not by the database: exact on SQLite too.
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);

        foreach (range(1, 10) as $i) {
            $this->sale($this->members['L'], '999999999999.999999', '2026-01-10', "l-{$i}");
        }

        $this->pair($component, '2026-01-01', '2026-02-01');
        $this->sale($this->members['R'], '100', '2026-02-10', 'r1');

        $february = $this->pair($component, '2026-02-01', '2026-03-01');

        $this->assertSame('9999999999999.99999 + 0 - 0 = 9999999999999.99999 -> 9999999999899.99999', $this->pairingResults($february)['P']['left']);
    }

    public function test_each_run_continues_exactly_where_the_last_ended(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $january = $this->pair($component, '2026-01-01', '2026-02-01', 'jan');
        $state = $this->pairingState();

        // The same key replays the run and moves nothing.
        $this->assertTrue($january->is($this->pair($component, '2026-01-01', '2026-02-01', 'jan')));
        $this->assertEquals($state, $this->pairingState());

        foreach ([
            'the same range again' => ['2026-01-01', '2026-02-01', 'already calculated through 2026-02-01 00:00:00'],
            'an overlap' => ['2026-01-15', '2026-02-15', 'overlaps it'],
            'a gap' => ['2026-03-01', '2026-04-01', 'would leave a gap'],
        ] as $case => [$from, $until, $reason]) {
            try {
                $this->pair($component, $from, $until, "other:{$from}");
                $this->fail("{$case} was accepted.");
            } catch (InvalidBinaryPairingRange $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage(), $case);
            }
        }

        $this->assertEquals($state, $this->pairingState());

        $february = $this->pair($component, '2026-02-01', '2026-03-01', 'feb');
        $cursor = BinaryPairingCursor::query()->sole();

        $this->assertSame(['2026-01-01 00:00:00', '2026-03-01 00:00:00'], [$cursor->started_at->format('Y-m-d H:i:s'), $cursor->through_at->format('Y-m-d H:i:s')]);
        $this->assertTrue($cursor->lastRun->is($february));
        $this->assertTrue($cursor->component->is($component));
    }

    public function test_a_first_run_starts_the_components_state_where_it_begins(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['L'], '100', '2026-01-10', 'jan');
        $this->sale($this->members['L'], '5', '2026-03-10', 'mar');

        $this->pair($component, '2026-03-01', '2026-04-01');

        $this->assertSame(['P left mar' => '5/5'], $this->carryLots($component));
        $this->assertSame('2026-03-01 00:00:00', BinaryPairingCursor::query()->sole()->started_at->format('Y-m-d H:i:s'));
    }

    public function test_a_new_plan_version_starts_with_no_carry_and_the_old_one_keeps_its_own(): void
    {
        $old = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['L'], '250', '2026-01-10', 'l1');
        $this->pair($old, '2026-01-01', '2026-02-01');

        $draft = $this->cloner()->cloneToNewDraft($old->planVersion);
        $this->lifecycle()->markValidated($draft);
        $new = PlanComponent::query()->where('plan_version_id', $draft->id)->sole();
        $this->sale($this->members['R'], '100', '2026-02-10', 'r1');

        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(['P' => ['left' => '0 + 0 - 0 = 0 -> 0', 'right' => '0 + 100 - 0 = 100 -> 100', 'pairs' => '0 x 100 = 0', 'commission' => false]], $this->pairingResults($this->pair($new, '2026-02-01', '2026-03-01', 'new:feb')));
        $this->assertSame(['P' => ['left' => '250 + 0 - 0 = 250 -> 150', 'right' => '0 + 100 - 0 = 100 -> 0', 'pairs' => '1 x 100 = 100', 'commission' => true]], $this->pairingResults($this->pair($old, '2026-02-01', '2026-03-01', 'old:feb')));
        $this->assertSame(2, BinaryPairingCursor::query()->count());
    }

    public function test_two_components_of_one_program_keep_separate_state(): void
    {
        $small = $this->pairingComponent($this->fixedPairing(['pair_quantity' => '50']), $this->plan);
        $large = $this->pairingComponent($this->fixedPairing(), Plan::factory()->for($this->plan->program)->create());
        $this->sale($this->members['L'], '150', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '150', '2026-01-10', 'r1');

        $this->assertSame('3 x 50 = 150', $this->pairingResults($this->pair($small, '2026-01-01', '2026-02-01', 'small'))['P']['pairs']);
        $this->assertSame('1 x 100 = 100', $this->pairingResults($this->pair($large, '2026-01-01', '2026-02-01', 'large'))['P']['pairs']);
        $this->assertSame(['P left l1' => '150/0', 'P right r1' => '150/0'], $this->carryLots($small));
        $this->assertSame(['P left l1' => '150/50', 'P right r1' => '150/50'], $this->carryLots($large));
    }

    public function test_an_entry_reversed_in_its_own_run_never_pairs(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['L'], '150', '2026-01-05', 'l1');
        $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-01-20'));
        $this->sale($this->members['R'], '150', '2026-01-10', 'r1');

        $run = $this->pair($component, '2026-01-01', '2026-02-01');

        $this->assertSame(['left' => '0 + 150 - 150 = 0 -> 0', 'right' => '0 + 150 - 0 = 150 -> 150', 'pairs' => '0 x 100 = 0', 'commission' => false], $this->pairingResults($run)['P']);
        $this->assertSame(['P left l1' => '150/0 reversed', 'P right r1' => '150/150'], $this->carryLots($component));
    }

    public function test_an_original_reversed_at_an_earlier_moment_never_pairs_once_taken_in(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['L'], '150', '2026-02-10', 'l1');
        $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-01-20'));
        $this->sale($this->members['R'], '150', '2026-02-10', 'r1');

        $this->pair($component, '2026-01-01', '2026-02-01');
        $february = $this->pair($component, '2026-02-01', '2026-03-01');

        $this->assertSame('0 + 150 - 150 = 0 -> 0', $this->pairingResults($february)['P']['left']);
        $this->assertSame(['P left l1' => '150/0 reversed', 'P right r1' => '150/150'], $this->carryLots($component));
    }

    public function test_a_reversal_of_unpaired_carry_takes_it_back(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['L2'], '150', '2026-01-10', 'l1');
        $this->pair($component, '2026-01-01', '2026-02-01');
        $reversal = $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));

        $february = $this->pair($component, '2026-02-01', '2026-03-01');

        $this->assertSame(['L' => '150 + 0 - 150 = 0 -> 0', 'P' => '150 + 0 - 150 = 0 -> 0'], array_map(static fn (array $result): string => $result['left'], $this->pairingResults($february)));
        $this->assertSame(['L left l1' => '150/0 reversed', 'P left l1' => '150/0 reversed'], $this->carryLots($component));
        $this->assertSame([$reversal->id], BinaryCarryLot::query()->distinct()->pluck('reversed_by_volume_entry_id')->all());
        $this->assertTrue(BinaryCarryLot::query()->where('member_id', $this->members['P']->id)->sole()->reversalEntry?->is($reversal));
    }

    public function test_a_reversal_of_activity_before_the_components_state_began_is_not_its_concern(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['L'], '150', '2025-12-10', 'dec');
        $this->reverse($sale, 'dec-refund', at: CarbonImmutable::parse('2026-01-05'));

        $run = $this->pair($component, '2026-01-01', '2026-02-01');

        $this->assertSame([[], []], [$this->carryLots($component), $this->pairingResults($run)]);
    }

    public function test_a_member_with_carry_gets_a_result_every_run_even_when_nothing_changes(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['L'], '250', '2026-01-10', 'l1');
        $this->pair($component, '2026-01-01', '2026-02-01');

        $february = $this->pair($component, '2026-02-01', '2026-03-01');

        $this->assertSame(['P' => ['left' => '250 + 0 - 0 = 250 -> 250', 'right' => '0 + 0 - 0 = 0 -> 0', 'pairs' => '0 x 100 = 0', 'commission' => false]], $this->pairingResults($february));
        $this->assertSame([], $this->allocationsOf($february));
    }

    public function test_pairing_moves_no_money_and_the_commission_follows_its_usual_lifecycle(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');

        $commission = $this->pair($component, '2026-01-01', '2026-02-01')->commissions()->sole();

        $this->assertSame([0, 0], [DB::table('mlm_ledger_transactions')->count(), DB::table('mlm_wallets')->count()]);
        $this->assertSame('posted', $this->poster()->post($this->approved($commission))->status->value);
        $this->assertSame('10', $this->balances()->forWallet($this->members['P']->wallets()->sole())->value());
    }

    public function test_a_preview_through_calculate_moves_no_state(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $context = fn (?string $component): CommissionCalculationContext => new CommissionCalculationContext(
            $this->plan->program,
            $this->app->make(CommissionComponentDriver::class)->definition($this->app->make(PlanDefinitionValidator::class)->definition($this->plan->versions()->sole())[0]),
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-02-01'),
            (string) DB::connection()->getName(),
            $component,
        );

        $preview = [...$this->app->make(BinaryPairingFixedStrategy::class)->calculate($context($component->id))];

        $this->assertCount(1, $preview);
        $this->assertSame([[], [], [], [], [], [], [], []], array_values($this->pairingState()));

        $this->expectException(InvalidBinaryPairingRange::class);
        $this->expectExceptionMessage('keeps its state per plan component');

        [...$this->app->make(BinaryPairingFixedStrategy::class)->calculate($context(null))];
    }

    public function test_the_transition_refuses_state_that_changed_after_it_was_read(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['L'], '250', '2026-01-10', 'l1');
        $january = $this->pair($component, '2026-01-01', '2026-02-01');
        $this->sale($this->members['R'], '100', '2026-02-10', 'r1');
        $lot = BinaryCarryLot::query()->where('member_id', $this->members['P']->id)->sole();

        foreach ([
            'its cursor is no longer at' => static fn () => DB::table('mlm_binary_pairing_cursors')->update(['through_at' => '2026-02-02 00:00:00']),
            "carry lot [{$lot->id}] is no longer as it was read" => static fn () => DB::table('mlm_binary_carry_lots')->where('id', $lot->id)->update(['remaining_millionths' => 200_000_000]),
        ] as $reason => $change) {
            // February, calculated from what is stored now; then the stored
            // state moves on before the transition is applied.
            $transition = $this->app->make(BinaryPairingFixedStrategy::class)->calculateStateful($this->contextFor($component, '2026-02-01', '2026-03-01'))->transition;
            $state = $this->pairingState();

            try {
                DB::transaction(function () use ($change, $transition, $january): void {
                    $change();
                    $transition->apply(DB::connection(), $january);
                });
                $this->fail("A transition overwrote newer state: {$reason}.");
            } catch (InvalidBinaryPairingState $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }

            $this->assertEquals($state, $this->pairingState());
        }
    }

    public function test_queries_do_not_grow_with_the_entries_a_run_takes_in(): void
    {
        $counts = [];

        foreach ([10, 100] as $entries) {
            $plan = Plan::factory()->for($this->plan->program)->create();
            $component = $this->pairingComponent($this->fixedPairing(['pair_quantity' => '1']), $plan);

            foreach (range(1, $entries) as $i) {
                $this->sale($this->members[$i % 2 === 0 ? 'L2' : 'R2'], '1', '2026-01-10', "n{$entries}-{$i}");
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->pair($component, '2026-01-01', '2026-02-01', "count:{$entries}");
            $counts[$entries] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        $this->assertSame($counts[10], $counts[100], json_encode($counts, JSON_THROW_ON_ERROR));
    }

    public function test_pairing_state_is_read_only_through_eloquent(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $this->pair($component, '2026-01-01', '2026-02-01');

        foreach ([BinaryPairingCursor::class, BinaryCarryLot::class, BinaryPairingResult::class, BinaryPairingAllocation::class] as $model) {
            $row = $model::query()->firstOrFail();

            foreach (['update' => static fn () => $row->forceFill(['created_at' => '2000-01-01 00:00:00'])->save(), 'delete' => static fn () => $row->delete(), 'create' => static fn () => $model::query()->forceCreate([])] as $write => $attempt) {
                try {
                    $attempt();
                    $this->fail("{$model} could be written through Eloquent: {$write}.");
                } catch (ImmutableCalculationRecord $exception) {
                    $this->assertStringContainsString('BinaryPairingStateTransition', $exception->getMessage());
                }
            }
        }
    }

    private function contextFor(PlanComponent $component, string $from, string $until): CommissionCalculationContext
    {
        $definition = collect($this->app->make(PlanDefinitionValidator::class)->definition($component->planVersion))->firstWhere('key', $component->key);

        return new CommissionCalculationContext(
            $this->plan->program,
            $this->app->make(CommissionComponentDriver::class)->definition($definition),
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($until),
            (string) DB::connection()->getName(),
            $component->id,
        );
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function refusedDefinitions(): array
    {
        $fixed = ['volume_type' => 'sales', 'pair_quantity' => '100', 'amount_per_pair' => '10'];
        $proportional = ['volume_type' => 'sales', 'pair_quantity' => '100', 'unit_amount' => '0.1', 'rounding' => 'half_even'];

        return [
            'fixed: an unknown field' => ['binary.pairing.fixed', [...$fixed, 'carry_expiry' => 30], 'unknown: carry_expiry'],
            'fixed: no pair quantity' => ['binary.pairing.fixed', array_diff_key($fixed, ['pair_quantity' => 1]), 'missing: pair_quantity'],
            'fixed: a zero pair quantity' => ['binary.pairing.fixed', [...$fixed, 'pair_quantity' => '0'], '"pair_quantity" is strictly positive'],
            'fixed: a negative pair quantity' => ['binary.pairing.fixed', [...$fixed, 'pair_quantity' => '-100'], '"pair_quantity" is strictly positive'],
            'fixed: a float pair quantity' => ['binary.pairing.fixed', [...$fixed, 'pair_quantity' => 100.0], '"pair_quantity"'],
            'fixed: a pair quantity too precise' => ['binary.pairing.fixed', [...$fixed, 'pair_quantity' => '0.0000001'], '"pair_quantity"'],
            'fixed: an uppercase volume type' => ['binary.pairing.fixed', [...$fixed, 'volume_type' => 'Sales'], '"volume_type"'],
            'fixed: a zero amount per pair' => ['binary.pairing.fixed', [...$fixed, 'amount_per_pair' => '0'], '"amount_per_pair" is a strictly positive award'],
            'fixed: an amount per pair beyond one posting' => ['binary.pairing.fixed', [...$fixed, 'amount_per_pair' => '9223372036854.775808'], 'more than one ledger posting holds'],
            'fixed: a unit amount instead' => ['binary.pairing.fixed', [...array_diff_key($fixed, ['amount_per_pair' => 1]), 'unit_amount' => '0.1'], 'unknown: unit_amount'],
            'proportional: no rounding' => ['binary.pairing.proportional', array_diff_key($proportional, ['rounding' => 1]), 'missing: rounding'],
            'proportional: a misspelled rounding' => ['binary.pairing.proportional', [...$proportional, 'rounding' => 'HALF_EVEN'], '"rounding" is one of'],
            'proportional: a negative unit amount' => ['binary.pairing.proportional', [...$proportional, 'unit_amount' => '-0.1'], '"unit_amount" is strictly positive'],
            'proportional: an amount per pair instead' => ['binary.pairing.proportional', [...$proportional, 'amount_per_pair' => '10'], 'unknown: amount_per_pair'],
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    #[DataProvider('refusedDefinitions')]
    public function test_a_definition_is_checked_exactly_before_it_is_validated(string $strategy, array $parameters, string $reason): void
    {
        $draft = $this->draft($this->plan);
        $this->systemAccounts()->openSystemAccount($this->plan->program, 'IDR', 'commission.payable');
        $this->addCommissionComponent($draft, ['strategy' => $strategy, 'currency' => 'IDR', 'source_account' => 'commission.payable', 'parameters' => $parameters]);

        $this->expectException(InvalidPlanDefinition::class);
        $this->expectExceptionMessage($reason);

        $this->lifecycle()->markValidated($draft);
    }

    public function test_rules_are_refused_rather_than_ignored(): void
    {
        $this->expectException(InvalidPlanDefinition::class);
        $this->expectExceptionMessage('binary.pairing.fixed rules');

        $this->fixedComponent('binary.pairing.fixed', $this->fixedPairing(), $this->plan, ['qualified' => $this->qualifyingRule()]);
    }

    public function test_the_package_registers_both_pairing_strategies(): void
    {
        $this->assertInstanceOf(BinaryPairingFixedStrategy::class, $this->strategies()->get('binary.pairing.fixed'));
        $this->assertInstanceOf(BinaryPairingProportionalStrategy::class, $this->strategies()->get('binary.pairing.proportional'));
        $this->assertInstanceOf(VolumeEntry::class, $this->sale($this->members['L'], '1', '2026-01-01', 'noop'));
        $this->assertInstanceOf(CalculationRun::class, $this->pair($this->pairingComponent($this->fixedPairing(), $this->plan), '2026-01-01', '2026-02-01'));
        $this->assertSame(0, Commission::query()->count());
    }
}
