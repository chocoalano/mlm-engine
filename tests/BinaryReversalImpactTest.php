<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Binary\Correction\BinaryReversalImpact;
use PandaBear\Mlm\Binary\Correction\BinaryReversalImpactAnalyzer;
use PandaBear\Mlm\Binary\Correction\BinaryReversalLotImpact;
use PandaBear\Mlm\Binary\Correction\BinaryReversalLotState;
use PandaBear\Mlm\Exceptions\InvalidBinaryCorrection;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Tests\Concerns\BuildsBinaryPairing;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsFixedCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;

/**
 * What a reversal reaches in binary pairing (ADR-024), read from the stored
 * lots and allocations alone: every lot, what remains and what pairings
 * consumed, and which results, runs and commissions consumed it — changing
 * nothing.
 */
final class BinaryReversalImpactTest extends DatabaseTestCase
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

        // P > L left > L2 left; P > R right. X placed only generically.
        $this->plan = Plan::factory()->create();
        $this->members = $this->members($this->plan->program, 'P', 'L', 'R', 'L2', 'X');
        $this->binaryAt($this->members, 'P', 'L', BinarySide::Left);
        $this->binaryAt($this->members, 'P', 'R', BinarySide::Right);
        $this->binaryAt($this->members, 'L', 'L2', BinarySide::Left);
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $this->placement()->place($this->members['X'], $this->members['P']);
        $this->travelBack();
    }

    public function test_an_entry_that_never_entered_binary_carry_has_no_impact(): void
    {
        $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['X'], '100', '2026-01-10', 'x1');
        $reversal = $this->reverse($sale, 'x1-refund', at: CarbonImmutable::parse('2026-02-05'));

        $impact = $this->analyze($reversal);

        $this->assertSame([[], 0, false], [$impact->lots, $impact->count(), $impact->requiresConsumedCorrection()]);
        $this->assertSame([$sale->id, $reversal->id, '2026-02-05 00:00:00', $this->plan->program_id], [$impact->originalEntryId, $impact->reversalEntryId, $impact->reversalEffectiveAt, $impact->programId]);
    }

    public function test_unpaired_carry_is_unconsumed(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['L'], '150', '2026-01-10', 'l1');
        $this->pair($component, '2026-01-01', '2026-02-01');

        $impact = $this->analyze($this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05')));

        $this->assertSame(['P left 150/150 consumed 0 unconsumed'], $this->lots($impact));
        $this->assertSame([], $impact->lots[0]->consumptions);
        $this->assertFalse($impact->requiresConsumedCorrection());
    }

    public function test_partly_paired_carry_reports_its_pairing_and_commission(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['L'], '150', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $run = $this->pair($component, '2026-01-01', '2026-02-01');

        $impact = $this->analyze($this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05')));
        $consumption = $impact->lots[0]->consumptions[0];
        $commission = $run->commissions()->sole();

        $this->assertSame(['P left 150/50 consumed 100 partially_consumed'], $this->lots($impact));
        $this->assertTrue($impact->requiresConsumedCorrection());
        $this->assertSame(
            ['left', '100', $run->id, '2026-01-01 00:00:00', '2026-02-01 00:00:00', $this->members['P']->id, '100', $commission->id, 'calculated', '10', 'IDR'],
            [$consumption->side, $consumption->quantity, $consumption->calculationRunId, $consumption->runFrom, $consumption->runUntil, $consumption->earningMemberId, $consumption->pairingConsumedQuantity, $consumption->commissionId, $consumption->commissionStatus, $consumption->commissionAmount, $consumption->commissionCurrency],
        );
        $this->assertSame(DB::table('mlm_binary_pairing_results')->where('calculation_run_id', $run->id)->value('id'), $consumption->pairingResultId);
    }

    public function test_wholly_paired_carry_is_fully_consumed(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $this->pair($component, '2026-01-01', '2026-02-01');

        $impact = $this->analyze($this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05')));

        $this->assertSame(['P left 100/0 consumed 100 fully_consumed'], $this->lots($impact));
        $this->assertSame([1, 0], [$impact->count(BinaryReversalLotState::FullyConsumed), $impact->count(BinaryReversalLotState::PartiallyConsumed)]);
    }

    public function test_carry_this_reversal_already_took_back_is_already_removed(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['L'], '150', '2026-01-10', 'l1');
        $this->pair($component, '2026-01-01', '2026-02-01');
        $reversal = $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));
        $this->pair($component, '2026-02-01', '2026-03-01');

        $impact = $this->analyze($reversal);

        $this->assertSame(['P left 150/0 consumed 0 already_removed'], $this->lots($impact));
        $this->assertFalse($impact->requiresConsumedCorrection());
    }

    public function test_every_ancestor_and_component_the_entry_reached_is_reported_apart(): void
    {
        $small = $this->pairingComponent($this->fixedPairing(['pair_quantity' => '50']), $this->plan);
        $large = $this->pairingComponent($this->fixedPairing(), Plan::factory()->for($this->plan->program)->create());
        $sale = $this->sale($this->members['L2'], '150', '2026-01-10', 'l2');
        $this->sale($this->members['R'], '60', '2026-01-10', 'r1');
        $this->pair($small, '2026-01-01', '2026-02-01', 'small');
        $this->pair($large, '2026-01-01', '2026-02-01', 'large');

        $impact = $this->analyze($this->reverse($sale, 'l2-refund', at: CarbonImmutable::parse('2026-02-05')));

        $this->assertCount(4, $impact->lots);
        $this->assertEqualsCanonicalizing(['L left 150/150 consumed 0 unconsumed', 'P left 150/100 consumed 50 partially_consumed'], $this->lots($impact, $small->id));
        $this->assertEqualsCanonicalizing(['L left 150/150 consumed 0 unconsumed', 'P left 150/150 consumed 0 unconsumed'], $this->lots($impact, $large->id));
        $this->assertTrue($impact->requiresConsumedCorrection());
    }

    public function test_a_lot_consumed_by_several_runs_lists_each_in_run_order(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['L'], '250', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $january = $this->pair($component, '2026-01-01', '2026-02-01');
        $this->sale($this->members['R'], '100', '2026-02-10', 'r2');
        $february = $this->pair($component, '2026-02-01', '2026-03-01');

        $lot = $this->analyze($this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-03-05')))->lots[0];

        $this->assertSame('250/50 consumed 200 partially_consumed', "{$lot->originalQuantity}/{$lot->remainingQuantity} consumed {$lot->consumedQuantity} {$lot->state->value}");
        $this->assertSame([[$january->id, '100'], [$february->id, '100']], array_map(static fn ($consumption): array => [$consumption->calculationRunId, $consumption->quantity], $lot->consumptions));
    }

    public function test_pairs_whose_award_rounded_to_nothing_are_reported_without_a_commission(): void
    {
        $component = $this->pairingComponent($this->proportionalPairing(['pair_quantity' => '0.1', 'unit_amount' => '0.000001']), $this->plan, 'binary.pairing.proportional');
        $sale = $this->sale($this->members['L'], '0.4', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '0.4', '2026-01-10', 'r1');
        $this->pair($component, '2026-01-01', '2026-02-01');

        $impact = $this->analyze($this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05')));
        $consumption = $impact->lots[0]->consumptions[0];

        $this->assertSame(['P left 0.4/0 consumed 0.4 fully_consumed'], $this->lots($impact));
        $this->assertSame(['0.4', '0.4', null, null, null, null], [$consumption->quantity, $consumption->pairingConsumedQuantity, $consumption->commissionId, $consumption->commissionStatus, $consumption->commissionAmount, $consumption->commissionCurrency]);
        $this->assertTrue($impact->requiresConsumedCorrection());
    }

    public function test_it_reports_commission_statuses_and_changes_nothing_at_all(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $this->poster()->post($this->approved($this->pair($component, '2026-01-01', '2026-02-01')->commissions()->sole()));
        $reversal = $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));
        $state = [$this->pairingState(), $this->ledgerRows(), DB::table('mlm_wallets')->get()->all(), DB::table('mlm_commission_adjustments')->count()];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $impact = $this->analyze($reversal);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame('posted', $impact->lots[0]->consumptions[0]->commissionStatus);
        $this->assertEquals($state, [$this->pairingState(), $this->ledgerRows(), DB::table('mlm_wallets')->get()->all(), DB::table('mlm_commission_adjustments')->count()]);
        $this->assertSame([], array_values(array_filter($queries, static fn (string $sql): bool => ! str_starts_with(strtolower($sql), 'select'))));
    }

    public function test_the_stored_reversal_decides_not_the_instance(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['L'], '150', '2026-01-10', 'l1');
        $other = $this->sale($this->members['R'], '70', '2026-01-10', 'r1');
        $this->pair($component, '2026-01-01', '2026-02-01');
        $reversal = $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));

        // Unsaved changes claiming another original and quantity.
        $reversal->reversal_of_id = $other->id;
        $reversal->quantity_millionths = -70_000_000;

        $this->assertSame([$sale->id, ['P left 150/150 consumed 0 unconsumed']], [$this->analyze($reversal)->originalEntryId, $this->lots($this->analyze($reversal))]);
    }

    public function test_an_entry_that_is_no_reversal_of_its_original_is_refused(): void
    {
        $sale = $this->sale($this->members['L'], '150', '2026-01-10', 'l1');
        $reversal = $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));

        $cases = [
            'an original' => [$sale, static fn () => null, 'is an original entry, not a reversal'],
            'another quantity' => [$reversal, fn () => DB::table('mlm_volume_entries')->where('id', $reversal->id)->update(['quantity_millionths' => -100_000_000]), 'its quantity is not exactly'],
            'another member' => [$reversal, fn () => DB::table('mlm_volume_entries')->where('id', $reversal->id)->update(['quantity_millionths' => -150_000_000, 'member_id' => $this->members['R']->id]), 'different members'],
        ];

        foreach ($cases as $case => [$entry, $corrupt, $reason]) {
            $corrupt();

            try {
                $this->analyze($entry);
                $this->fail("{$case} was analysed.");
            } catch (InvalidBinaryCorrection $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage(), $case);
            }
        }
    }

    public function test_carry_whose_numbers_do_not_add_up_is_refused(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['L'], '150', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $this->pair($component, '2026-01-01', '2026-02-01');
        $reversal = $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));
        DB::table('mlm_binary_carry_lots')->where('source_volume_entry_id', $sale->id)->update(['remaining_millionths' => 60_000_000]);

        $this->expectException(InvalidBinaryCorrection::class);
        $this->expectExceptionMessage('its remainder 60000000 and net pairings 100000000 do not add up to its 150000000 millionths');

        $this->analyze($reversal);
    }

    public function test_the_impact_serialises_deterministically(): void
    {
        $component = $this->pairingComponent($this->fixedPairing(), $this->plan);
        $sale = $this->sale($this->members['L2'], '150', '2026-01-10', 'l2');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $run = $this->pair($component, '2026-01-01', '2026-02-01');
        $reversal = $this->reverse($sale, 'l2-refund', at: CarbonImmutable::parse('2026-02-05'));

        $array = $this->analyze($reversal)->toArray();
        $lot = static fn (string $member): array => (array) DB::table('mlm_binary_carry_lots')->where('member_id', $member)->first();

        $this->assertSame($array, $this->analyze($reversal)->toArray());
        $this->assertSame(json_encode($array), json_encode($this->analyze($reversal)->toArray()));
        $this->assertSame([
            'program_id' => $this->plan->program_id,
            'original_volume_entry_id' => $sale->id,
            'reversal_volume_entry_id' => $reversal->id,
            'reversal_effective_at' => '2026-02-05 00:00:00',
            'lot_count' => 2,
            'unconsumed' => 1,
            'partially_consumed' => 1,
            'fully_consumed' => 0,
            'already_removed' => 0,
            'requires_consumed_correction' => true,
            'lots' => array_values(array_map(static fn (BinaryReversalLotImpact $impact): array => $impact->toArray(), $this->analyze($reversal)->lots)),
        ], $array);
        $this->assertSame(['member_id' => $this->members['L']->id, 'state' => 'unconsumed', 'consumptions' => []], array_intersect_key(collect($array['lots'])->firstWhere('member_id', $this->members['L']->id), array_flip(['member_id', 'state', 'consumptions'])));
        $this->assertSame([
            'allocation_id' => DB::table('mlm_binary_pairing_allocations')->where('binary_carry_lot_id', $lot($this->members['P']->id)['id'])->value('id'),
            'side' => 'left',
            'quantity' => '100',
            'pairing_result_id' => DB::table('mlm_binary_pairing_results')->where('calculation_run_id', $run->id)->where('member_id', $this->members['P']->id)->value('id'),
            'calculation_run_id' => $run->id,
            'run_from' => '2026-01-01 00:00:00',
            'run_until' => '2026-02-01 00:00:00',
            'earning_member_id' => $this->members['P']->id,
            'pairing_consumed_quantity' => '100',
            'commission_id' => $run->commissions()->sole()->id,
            'commission_status' => 'calculated',
            'commission_amount' => '10',
            'commission_currency' => 'IDR',
            'invalidated_quantity' => '0',
            'restored_quantity' => '0',
            'net_quantity' => '100',
        ], collect($array['lots'])->firstWhere('member_id', $this->members['P']->id)['consumptions'][0]);
    }

    public function test_queries_do_not_grow_with_the_allocations_it_reports(): void
    {
        $counts = [];

        // Each case in its own months, so neither takes in the other's sales.
        foreach ([1 => 1, 6 => 2] as $runs => $start) {
            $plan = Plan::factory()->for($this->plan->program)->create();
            $component = $this->pairingComponent($this->fixedPairing(['pair_quantity' => '10']), $plan);
            $sale = $this->sale($this->members['L2'], '100', sprintf('2026-%02d-05', $start), "l2-{$runs}");

            // One more pairing per month, each drawing on the same lot.
            foreach (range($start, $start + $runs - 1) as $month) {
                $this->sale($this->members['R'], '10', sprintf('2026-%02d-10', $month), "r-{$runs}-{$month}");
                $this->pair($component, sprintf('2026-%02d-01', $month), sprintf('2026-%02d-01', $month + 1), "run-{$runs}-{$month}");
            }

            $reversal = $this->reverse($sale, "l2-refund-{$runs}", at: CarbonImmutable::parse('2026-09-05'));
            DB::flushQueryLog();
            DB::enableQueryLog();
            $impact = $this->analyze($reversal);
            $counts[$runs] = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertCount($runs, collect($impact->lots)->firstWhere('memberId', $this->members['P']->id)?->consumptions ?? []);
        }

        $this->assertSame($counts[1], $counts[6], json_encode($counts, JSON_THROW_ON_ERROR));
    }

    private function analyze(VolumeEntry $reversal): BinaryReversalImpact
    {
        return $this->app->make(BinaryReversalImpactAnalyzer::class)->analyze($reversal);
    }

    /**
     * "owner side original/remaining consumed X state", by lot, optionally
     * of one component.
     *
     * @return list<string>
     */
    private function lots(BinaryReversalImpact $impact, ?string $component = null): array
    {
        $codes = Member::query()->pluck('member_code', 'id');

        return array_map(
            static fn (BinaryReversalLotImpact $lot): string => "{$codes[$lot->memberId]} {$lot->side} {$lot->originalQuantity}/{$lot->remainingQuantity} consumed {$lot->consumedQuantity} {$lot->state->value}",
            $component === null ? $impact->lots : $impact->forComponent($component),
        );
    }
}
