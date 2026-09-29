<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Binary\Correction\BinaryReversalImpactAnalyzer;
use PandaBear\Mlm\Binary\Correction\BinaryReversalLotState;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Exceptions\InvalidBinaryCorrection;
use PandaBear\Mlm\Models\BinaryPairingCorrection;
use PandaBear\Mlm\Models\BinaryPairingRestoration;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Tests\Concerns\BuildsBinaryPairing;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsFixedCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PandaBear\Mlm\Volume\Quantity;
use RuntimeException;

/**
 * A reversal of carry an earlier pair consumed (ADR-025): in the run the
 * reversal falls in, what the source still holds is taken back, every part
 * of an earlier pair it fed is invalidated, and the same quantity goes back
 * to the other side's carry — newest source first — where it may pair
 * again. The earlier runs, results, allocations and commissions stay as
 * they were; a journal explains them.
 */
final class BinaryConsumedCarryCorrectionTest extends DatabaseTestCase
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

    private PlanComponent $component;

    protected function setUp(): void
    {
        parent::setUp();

        // P > L left > L2 left; P > R right.
        $this->plan = Plan::factory()->create();
        $this->members = $this->members($this->plan->program, 'P', 'L', 'R', 'L2');
        $this->binaryAt($this->members, 'P', 'L', BinarySide::Left);
        $this->binaryAt($this->members, 'P', 'R', BinarySide::Right);
        $this->binaryAt($this->members, 'L', 'L2', BinarySide::Left);
        $this->component = $this->pairingComponent($this->fixedPairing(), $this->plan);
    }

    public function test_a_wholly_paired_source_is_undone_and_the_other_side_gets_its_carry_back(): void
    {
        $sale = $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $january = $this->pair($this->component, '2026-01-01', '2026-02-01');
        $history = [$this->rows('mlm_binary_pairing_results'), $this->rows('mlm_binary_pairing_allocations'), $this->rows('mlm_commissions')];
        $reversal = $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));

        $february = $this->pair($this->component, '2026-02-01', '2026-03-01');

        $this->assertSame(['P left l1' => '100/0 reversed', 'P right r1' => '100/100'], $this->carryLots($this->component));
        $this->assertSame(['P left l1 100 from '.$january->id.' with commission'], $this->corrections());
        $this->assertSame(['P right r1 100'], $this->restorations());
        $this->assertSame(['P' => ['left' => '0 + 0 - 0 = 0 -> 0', 'right' => '0 + 0 - 0 = 100 -> 100', 'pairs' => '0 x 100 = 0', 'commission' => false, 'restored' => 'left 0, right 100']], $this->pairingResults($february));

        // The earlier run's facts, and its commission, are untouched; no money moved.
        $this->assertEquals($history, [$this->rows('mlm_binary_pairing_results', $january), $this->rows('mlm_binary_pairing_allocations'), $this->rows('mlm_commissions')]);
        $this->assertSame([0, 0, 0], [DB::table('mlm_ledger_transactions')->count(), DB::table('mlm_wallets')->count(), DB::table('mlm_commission_adjustments')->count()]);

        $correction = BinaryPairingCorrection::query()->sole();
        $this->assertSame([$reversal->id, $sale->id, $february->id, BinarySide::Left, '100'], [$correction->reversal_volume_entry_id, $correction->original_volume_entry_id, $correction->calculation_run_id, $correction->invalidated_side, $correction->quantity->value()]);
        $this->assertTrue($correction->commission?->is($january->commissions()->sole()));
        $this->assertTrue($correction->restorations()->sole()->carryLot->sourceEntry->is(VolumeEntry::query()->where('idempotency_key', 'r1')->sole()));
    }

    public function test_the_analyzer_sees_the_correction_as_done(): void
    {
        $sale = $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $other = $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $this->pair($this->component, '2026-01-01', '2026-02-01');
        $reversal = $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));
        $analyzer = $this->app->make(BinaryReversalImpactAnalyzer::class);

        $this->assertTrue($analyzer->analyze($reversal)->requiresConsumedCorrection());

        $this->pair($this->component, '2026-02-01', '2026-03-01');
        $impact = $analyzer->analyze($reversal);
        $lot = $impact->lots[0];

        $this->assertFalse($impact->requiresConsumedCorrection());
        $this->assertSame([BinaryReversalLotState::AlreadyRemoved, '100', '100', '0', '0'], [$lot->state, $lot->allocatedQuantity, $lot->invalidatedQuantity, $lot->restoredQuantity, $lot->consumedQuantity]);

        // The other side's lot: allocated 100, all of it given back.
        $this->assertSame(['100', '100', '0'], $this->netOf($other));
    }

    public function test_a_partly_paired_source_loses_its_open_carry_and_its_pairs(): void
    {
        $sale = $this->sale($this->members['L'], '150', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $this->pair($this->component, '2026-01-01', '2026-02-01');
        $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));

        $february = $this->pair($this->component, '2026-02-01', '2026-03-01');

        $this->assertSame(['P left l1' => '150/0 reversed', 'P right r1' => '100/100'], $this->carryLots($this->component));
        $this->assertSame('50 + 0 - 50 = 0 -> 0', $this->pairingResults($february)['P']['left']);
        $this->assertSame(['P right r1 100'], $this->restorations());
        $this->assertSame(['150', '100', '0'], $this->netOf($sale));
    }

    public function test_the_other_side_is_given_back_newest_source_first(): void
    {
        // One pair: left la 40 + lb 60; right r1 30, r2 50, r3 20, oldest first.
        $this->sale($this->members['L'], '40', '2026-01-03', 'la');
        $source = $this->sale($this->members['L'], '60', '2026-01-04', 'lb');
        $this->sale($this->members['R'], '30', '2026-01-05', 'r1');
        $this->sale($this->members['R'], '50', '2026-01-06', 'r2');
        $this->sale($this->members['R'], '20', '2026-01-07', 'r3');
        $this->pair($this->component, '2026-01-01', '2026-02-01');
        $this->reverse($source, 'lb-refund', at: CarbonImmutable::parse('2026-02-05'));

        $this->pair($this->component, '2026-02-01', '2026-03-01');

        $this->assertSame(['P right r2 40', 'P right r3 20'], $this->restorations());
        $this->assertSame(['P left la' => '40/0', 'P left lb' => '60/0 reversed', 'P right r1' => '30/0', 'P right r2' => '50/40', 'P right r3' => '20/20'], $this->carryLots($this->component));
    }

    public function test_an_allocation_is_never_given_back_beyond_what_still_counts(): void
    {
        // One pair: left la 30 + lb 70; right r1 100.
        $first = $this->sale($this->members['L'], '30', '2026-01-03', 'la');
        $second = $this->sale($this->members['L'], '70', '2026-01-04', 'lb');
        $other = $this->sale($this->members['R'], '100', '2026-01-05', 'r1');
        $this->pair($this->component, '2026-01-01', '2026-02-01');

        $this->reverse($first, 'la-refund', at: CarbonImmutable::parse('2026-02-05'));
        $this->pair($this->component, '2026-02-01', '2026-03-01');

        // r1's allocation now counts 70: all the second correction may take.
        $this->assertSame(['100', '30', '70'], $this->netOf($other));

        $this->reverse($second, 'lb-refund', at: CarbonImmutable::parse('2026-03-05'));
        $this->pair($this->component, '2026-03-01', '2026-04-01');

        $this->assertSame(['P right r1 30', 'P right r1 70'], $this->restorations());
        $this->assertSame(['100', '100', '0'], $this->netOf($other));
        $this->assertSame('P right r1', array_key_last(array_filter($this->carryLots($this->component), static fn (string $lot): bool => $lot === '100/100')));
    }

    public function test_a_pair_both_of_whose_sources_are_reversed_in_one_run_is_undone_once(): void
    {
        $left = $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $right = $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $this->pair($this->component, '2026-01-01', '2026-02-01');
        $this->reverse($left, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));
        $second = $this->reverse($right, 'r1-refund', at: CarbonImmutable::parse('2026-02-06'));

        $february = $this->pair($this->component, '2026-02-01', '2026-03-01');

        $this->assertSame(['P left l1' => '100/0 reversed', 'P right r1' => '100/0 reversed'], $this->carryLots($this->component));
        $this->assertCount(1, $this->corrections());
        $this->assertSame(['P right r1 100'], $this->restorations());
        $this->assertSame(['right' => '0 + 0 - 100 = 0 -> 0', 'restored' => 'left 0, right 100'], array_intersect_key($this->pairingResults($february)['P'], array_flip(['right', 'restored'])));
        $this->assertSame(BinaryReversalLotState::AlreadyRemoved, $this->app->make(BinaryReversalImpactAnalyzer::class)->analyze($second)->lots[0]->state);
    }

    public function test_carry_given_back_pairs_again_at_the_runs_close(): void
    {
        $sale = $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $this->pair($this->component, '2026-01-01', '2026-02-01');
        $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));
        $this->sale($this->members['L'], '100', '2026-02-10', 'l2');

        $february = $this->pair($this->component, '2026-02-01', '2026-03-01');

        $this->assertSame(['left' => '0 + 100 - 0 = 100 -> 0', 'right' => '0 + 0 - 0 = 100 -> 0', 'pairs' => '1 x 100 = 100', 'commission' => true, 'restored' => 'left 0, right 100'], $this->pairingResults($february)['P']);
        $this->assertEqualsCanonicalizing(['P left l2 100', 'P right r1 100'], $this->allocationsOf($february));
        $this->assertSame(['P left l1' => '100/0 reversed', 'P left l2' => '100/0', 'P right r1' => '100/0'], $this->carryLots($this->component));
        $this->assertCount(1, $this->corrections());
    }

    public function test_a_pair_that_earned_nothing_after_rounding_is_undone_all_the_same(): void
    {
        $component = $this->pairingComponent($this->proportionalPairing(['pair_quantity' => '0.1', 'unit_amount' => '0.000001']), Plan::factory()->for($this->plan->program)->create(), 'binary.pairing.proportional');
        $sale = $this->sale($this->members['L'], '0.4', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '0.4', '2026-01-10', 'r1');
        $this->pair($component, '2026-01-01', '2026-02-01');
        $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));

        $this->pair($component, '2026-02-01', '2026-03-01');

        $this->assertSame(['P left l1 0.4 from '.CalculationRun::query()->where('idempotency_key', 'pair:2026-01-01')->value('id').' without commission'], $this->corrections());
        $this->assertSame(['P left l1' => '0.4/0 reversed', 'P right r1' => '0.4/0.4'], $this->carryLots($component));
    }

    public function test_a_source_paired_over_several_runs_is_undone_run_by_run(): void
    {
        $sale = $this->sale($this->members['L'], '250', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $january = $this->pair($this->component, '2026-01-01', '2026-02-01');
        $this->sale($this->members['R'], '100', '2026-02-10', 'r2');
        $february = $this->pair($this->component, '2026-02-01', '2026-03-01');
        $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-03-05'));

        $march = $this->pair($this->component, '2026-03-01', '2026-04-01');

        $this->assertSame(["P left l1 100 from {$january->id} with commission", "P left l1 100 from {$february->id} with commission"], $this->corrections());
        $this->assertSame(['P right r1 100', 'P right r2 100'], $this->restorations());
        $this->assertSame(['left' => '50 + 0 - 50 = 0 -> 0', 'right' => '0 + 0 - 0 = 200 -> 200'], array_intersect_key($this->pairingResults($march)['P'], array_flip(['left', 'right'])));
    }

    public function test_each_ancestor_is_corrected_in_its_own_leg_and_each_component_only_by_its_own_run(): void
    {
        $other = $this->pairingComponent($this->fixedPairing(['pair_quantity' => '50']), Plan::factory()->for($this->plan->program)->create());
        $sale = $this->sale($this->members['L2'], '100', '2026-01-10', 'l2');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $this->pair($this->component, '2026-01-01', '2026-02-01', 'main:jan');
        $this->pair($other, '2026-01-01', '2026-02-01', 'other:jan');
        $this->reverse($sale, 'l2-refund', at: CarbonImmutable::parse('2026-02-05'));
        $untouched = $this->carryLots($other);

        $this->pair($this->component, '2026-02-01', '2026-03-01', 'main:feb');

        // P's pair is undone; L's carry, never paired, is simply taken back.
        $this->assertSame(['L left l2' => '100/0 reversed', 'P left l2' => '100/0 reversed', 'P right r1' => '100/100'], $this->carryLots($this->component));
        $this->assertSame($untouched, $this->carryLots($other));
        $this->assertSame(1, BinaryPairingCorrection::query()->where('plan_component_id', $this->component->id)->count());

        $this->pair($other, '2026-02-01', '2026-03-01', 'other:feb');

        $this->assertSame(['L left l2' => '100/0 reversed', 'P left l2' => '100/0 reversed', 'P right r1' => '100/100'], $this->carryLots($other));
        $this->assertSame(1, BinaryPairingCorrection::query()->where('plan_component_id', $other->id)->count());
    }

    public function test_a_replay_corrects_nothing_again(): void
    {
        $sale = $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $this->pair($this->component, '2026-01-01', '2026-02-01');
        $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));
        $february = $this->pair($this->component, '2026-02-01', '2026-03-01', 'feb');
        $state = $this->pairingState();

        $this->assertTrue($february->is($this->pair($this->component, '2026-02-01', '2026-03-01', 'feb')));
        $this->assertEquals($state, $this->pairingState());
    }

    public function test_a_run_that_fails_after_planning_its_corrections_keeps_none_of_them(): void
    {
        $sale = $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $this->pair($this->component, '2026-01-01', '2026-02-01');
        $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));
        $state = $this->pairingState();

        DB::listen(static function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'insert into') && str_contains($query->sql, 'mlm_binary_pairing_restorations')) {
                throw new RuntimeException('The journal could not be written.');
            }
        });

        try {
            $this->pair($this->component, '2026-02-01', '2026-03-01');
            $this->fail('A run whose journal failed was kept.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The journal could not be written.', $exception->getMessage());
        }

        $this->assertEquals($state, $this->pairingState());
        $this->assertSame([0, 0], [BinaryPairingCorrection::query()->count(), BinaryPairingRestoration::query()->count()]);
    }

    public function test_history_that_cannot_be_undone_exactly_is_refused(): void
    {
        $sale = $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $other = $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $this->pair($this->component, '2026-01-01', '2026-02-01');
        $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));

        // A raw write: the other side's source marked reversed, though its
        // allocation still counts as paired.
        DB::table('mlm_binary_carry_lots')->where('source_volume_entry_id', $other->id)->update(['reversed_by_volume_entry_id' => $this->reverse($other, 'r1-refund', at: CarbonImmutable::parse('2026-03-05'))->id]);
        $state = $this->pairingState();

        try {
            $this->pair($this->component, '2026-02-01', '2026-03-01');
            $this->fail('Carry was given back to a reversed source.');
        } catch (InvalidBinaryCorrection $exception) {
            $this->assertStringContainsString('its source is reversed, yet allocation', $exception->getMessage());
        }

        $this->assertEquals($state, $this->pairingState());
    }

    public function test_the_journal_is_read_only_through_eloquent(): void
    {
        $sale = $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $this->pair($this->component, '2026-01-01', '2026-02-01');
        $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));
        $this->pair($this->component, '2026-02-01', '2026-03-01');

        foreach ([BinaryPairingCorrection::class, BinaryPairingRestoration::class] as $model) {
            $row = $model::query()->firstOrFail();

            foreach ([static fn () => $row->forceFill(['created_at' => '2000-01-01 00:00:00'])->save(), static fn () => $row->delete(), static fn () => $model::query()->forceCreate([])] as $attempt) {
                try {
                    $attempt();
                    $this->fail("{$model} was written through Eloquent.");
                } catch (ImmutableCalculationRecord) {
                }
            }
        }

        $this->assertSame(1, BinaryPairingRestoration::query()->sole()->correction->restorations()->count());
        $this->assertTrue(BinaryPairingRestoration::query()->sole()->restoredAllocation->result->is(BinaryPairingCorrection::query()->sole()->result));
    }

    /**
     * Every correction: "owner side source quantity from run with|without commission".
     *
     * @return list<string>
     */
    private function corrections(): array
    {
        $codes = Member::query()->pluck('member_code', 'id');
        $keys = DB::table('mlm_volume_entries')->pluck('idempotency_key', 'id');

        return BinaryPairingCorrection::query()->with('result')->orderBy('id')->get()
            ->map(fn (BinaryPairingCorrection $correction): string => sprintf(
                '%s %s %s %s from %s %s',
                $codes[$correction->member_id],
                $correction->invalidated_side->value,
                $keys[$correction->original_volume_entry_id],
                $correction->quantity->value(),
                $correction->result->calculation_run_id,
                $correction->commission_id === null ? 'without commission' : 'with commission',
            ))
            ->sort()->values()->all();
    }

    /**
     * Every restoration: "owner side source quantity", sorted.
     *
     * @return list<string>
     */
    private function restorations(): array
    {
        $codes = Member::query()->pluck('member_code', 'id');
        $keys = DB::table('mlm_volume_entries')->pluck('idempotency_key', 'id');

        return BinaryPairingRestoration::query()->with('carryLot')->get()
            ->map(static fn (BinaryPairingRestoration $restoration): string => "{$codes[$restoration->carryLot->member_id]} {$restoration->side->value} {$keys[$restoration->carryLot->source_volume_entry_id]} {$restoration->quantity->value()}")
            ->sort()->values()->all();
    }

    /**
     * P's lot of the entry: quantity, what corrections released of what its
     * allocations drew, and what those still count — as quantities.
     *
     * @return array{string, string, string}
     */
    private function netOf(VolumeEntry $entry): array
    {
        $lot = DB::table('mlm_binary_carry_lots')->where('plan_component_id', $this->component->id)->where('member_id', $this->members['P']->id)->where('source_volume_entry_id', $entry->id)->sole();
        $allocations = DB::table('mlm_binary_pairing_allocations')->where('binary_carry_lot_id', $lot->id)->pluck('id');
        $allocated = (int) DB::table('mlm_binary_pairing_allocations')->where('binary_carry_lot_id', $lot->id)->sum('quantity_millionths');
        $released = (int) DB::table('mlm_binary_pairing_corrections')->whereIn('invalidated_allocation_id', $allocations)->sum('quantity_millionths')
            + (int) DB::table('mlm_binary_pairing_restorations')->whereIn('restored_allocation_id', $allocations)->sum('quantity_millionths');

        return array_map(static fn (int $millionths): string => Quantity::fromMillionths($millionths)->value(), [(int) $lot->quantity_millionths, $released, $allocated - $released]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, ?CalculationRun $run = null): array
    {
        return DB::table($table)->when($run !== null, static fn ($query) => $query->where('calculation_run_id', $run->id))->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }
}
