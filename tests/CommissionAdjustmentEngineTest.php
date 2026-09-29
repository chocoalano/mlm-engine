<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Commission\CommissionAdjustmentEngine;
use PandaBear\Mlm\Commission\CommissionAdjustmentOutcome;
use PandaBear\Mlm\Commission\CommissionAdjustmentResult;
use PandaBear\Mlm\Commission\CommissionLifecycle;
use PandaBear\Mlm\Commission\CommissionPoster;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Exceptions\InvalidCommissionAdjustment;
use PandaBear\Mlm\Exceptions\InvalidCommissionPosting;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionAdjustment;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsFixedCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A later reversal of a source entry claws back every commission earned
 * from it — by exactly its stored amount, through its lifecycle or the
 * ledger's reversal — and is safe to run again.
 */
final class CommissionAdjustmentEngineTest extends DatabaseTestCase
{
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

    private VolumeEntry $entry;

    private PlanComponent $unilevel;

    protected function setUp(): void
    {
        parent::setUp();

        // Alice sponsors Bob, Bob Charlie, Charlie Diana; Diana sells.
        $this->plan = Plan::factory()->create();
        $this->members = $this->members($this->plan->program, 'ALICE', 'BOB', 'CHARLIE', 'DIANA', 'EVE');
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sponsorAt($this->members['CHARLIE'], $this->members['BOB'], '2026-01-01 00:00:00');
        $this->sponsorAt($this->members['DIANA'], $this->members['CHARLIE'], '2026-01-01 00:00:00');
        $this->entry = $this->sale($this->members['DIANA'], '150', '2026-01-10', 'order:A');
        $this->unilevel = $this->fixedComponent('unilevel.fixed', $this->unilevelParameters(), $this->plan);
    }

    /**
     * @return array<string, array{Closure(self, Commission): Commission, string}>
     */
    public static function unpaidStatuses(): array
    {
        return [
            'calculated' => [static fn (self $test, Commission $commission): Commission => $commission, 'calculated'],
            'pending' => [static fn (self $test, Commission $commission): Commission => $test->commissionLifecycle()->markPending($commission), 'pending'],
            'approved' => [static fn (self $test, Commission $commission): Commission => $test->approved($commission), 'approved'],
        ];
    }

    /**
     * @param  Closure(self, Commission): Commission  $reach
     */
    #[DataProvider('unpaidStatuses')]
    public function test_an_unpaid_commission_is_cancelled_and_no_money_moves(Closure $reach, string $before): void
    {
        $commission = $reach($this, $this->depth(1, $this->monthly($this->unilevel, '2026-01')));
        $calculated = $this->calculatedFacts($commission);
        $ledger = $this->ledgerRows();
        $reversal = $this->reverse($this->entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10 08:00:00'));

        $result = $this->process($reversal);
        $adjustment = $this->adjustmentOf($result, $commission);

        $this->assertSame(CommissionStatus::Cancelled, $commission->refresh()->status);
        $this->assertSame($calculated, $this->calculatedFacts($commission));
        $this->assertSame($ledger, $this->ledgerRows());
        $this->assertSame(0, Wallet::query()->count());
        $this->assertSame(
            [$this->plan->program_id, 'clawback', 'volume-entry-reversal', $reversal->id, '-10', '2026-04-10 08:00:00', CommissionAdjustmentOutcome::Cancelled, null],
            [$adjustment->program_id, $adjustment->type, $adjustment->source_type, $adjustment->source_id, $adjustment->amount->value(), $adjustment->occurred_at->format('Y-m-d H:i:s'), $adjustment->outcome, $adjustment->ledger_transaction_id],
        );
        $this->assertSame([
            'adjustment' => ['amount' => '-10', 'ledger_transaction_id' => null, 'outcome' => 'cancelled'],
            'commission' => [
                'amount' => '10',
                'calculation_run_id' => $commission->calculation_run_id,
                'candidate_key' => $commission->candidate_key,
                'id' => $commission->id,
                'member_id' => $this->members['CHARLIE']->id,
                'status_before' => $before,
            ],
            'reason' => 'source-reversal',
            'source' => [
                'original_volume_entry_id' => $this->entry->id,
                'reversal_effective_at' => '2026-04-10 08:00:00',
                'reversal_volume_entry_id' => $reversal->id,
            ],
        ], $adjustment->trace);
    }

    public function test_a_posted_commission_is_reversed_through_the_ledger_at_the_reversals_moment(): void
    {
        $posted = $this->poster()->post($this->approved($this->depth(1, $this->monthly($this->unilevel, '2026-01'))));
        $original = LedgerTransaction::query()->findOrFail($posted->ledger_transaction_id);
        $originalRow = (array) DB::table('mlm_ledger_transactions')->where('id', $original->id)->first();
        $wallet = Wallet::query()->where('member_id', $this->members['CHARLIE']->id)->sole();
        $this->assertSame('10', $this->balances()->forWallet($wallet)->value());
        $reversal = $this->reverse($this->entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10 08:00:00'));

        $adjustment = $this->adjustmentOf($this->process($reversal), $posted);
        $reversed = $posted->refresh();

        $this->assertSame(CommissionStatus::Reversed, $reversed->status);
        $this->assertSame(CommissionAdjustmentOutcome::Reversed, $adjustment->outcome);
        $this->assertSame($reversed->reversal_ledger_transaction_id, $adjustment->ledger_transaction_id);
        $this->assertSame('2026-04-10 08:00:00', $adjustment->ledgerTransaction?->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame($original->id, $adjustment->ledgerTransaction?->reversal_of_id);
        $this->assertSame('0', $this->balances()->forWallet($wallet)->value());
        $this->assertSame('0', $this->balances()->forAccount($posted->run->sourceAccount)->value());
        $this->assertEquals($originalRow, (array) DB::table('mlm_ledger_transactions')->where('id', $original->id)->first());
        $this->assertSame('-10', $adjustment->amount->value());
    }

    public function test_a_cancelled_commission_is_left_as_it_is(): void
    {
        $cancelled = $this->commissionLifecycle()->cancel($this->depth(1, $this->monthly($this->unilevel, '2026-01')));
        $row = (array) DB::table('mlm_commissions')->where('id', $cancelled->id)->first();
        $ledger = $this->ledgerRows();

        $adjustment = $this->adjustmentOf($this->process($this->reverse($this->entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10'))), $cancelled);

        $this->assertSame([CommissionAdjustmentOutcome::AlreadyCancelled, null, '-10'], [$adjustment->outcome, $adjustment->ledger_transaction_id, $adjustment->amount->value()]);
        $this->assertEquals($row, (array) DB::table('mlm_commissions')->where('id', $cancelled->id)->first());
        $this->assertSame($ledger, $this->ledgerRows());
    }

    public function test_a_commission_already_reversed_is_not_reversed_again(): void
    {
        $posted = $this->poster()->post($this->approved($this->depth(1, $this->monthly($this->unilevel, '2026-01'))));
        // Reversed by hand, at another moment than the source's reversal.
        $reversed = $this->poster()->reverse($posted, CarbonImmutable::parse('2026-02-01 00:00:00'));
        $ledger = $this->ledgerRows();

        $adjustment = $this->adjustmentOf($this->process($this->reverse($this->entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10'))), $reversed);

        $this->assertSame([CommissionAdjustmentOutcome::AlreadyReversed, $reversed->reversal_ledger_transaction_id], [$adjustment->outcome, $adjustment->ledger_transaction_id]);
        $this->assertSame(CommissionStatus::Reversed, $reversed->refresh()->status);
        $this->assertSame($ledger, $this->ledgerRows());
        $this->assertSame('2026-04-10 00:00:00', $adjustment->trace['source']['reversal_effective_at']);
        $this->assertSame('2026-02-01 00:00:00', $adjustment->ledgerTransaction?->occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_every_commission_of_the_entry_is_corrected_on_its_own(): void
    {
        // Two runs — three depths each, and a direct award — over one entry.
        $january = $this->monthly($this->unilevel, '2026-01');
        $again = $this->monthly($this->unilevel, '2026-01', 'run:2026-01-again');
        $direct = $this->monthly($this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan), '2026-01', 'run:direct');
        // Another entry's commissions, from another month, are not touched.
        $this->sale($this->members['DIANA'], '150', '2026-02-12', 'order:B');
        $unrelated = $this->monthly($this->unilevel, '2026-02', 'run:other')->commissions()->get();
        $this->assertCount(3, $unrelated);

        $this->poster()->post($this->approved($this->depth(1, $january)));
        $this->approved($this->depth(2, $january));
        $this->commissionLifecycle()->cancel($this->depth(3, $january));
        $this->poster()->reverse($this->poster()->post($this->approved($this->depth(1, $again))), CarbonImmutable::parse('2026-02-01'));
        $unrelatedRows = DB::table('mlm_commissions')->whereIn('id', $unrelated->modelKeys())->orderBy('id')->get()->all();

        $result = $this->process($this->reverse($this->entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10')));

        $outcomes = [];

        foreach ($result->adjustments as $adjustment) {
            $outcomes[$adjustment->commission->run->idempotency_key.'#'.$adjustment->commission->trace['recipient']['depth']] = $adjustment->outcome->value;
        }

        ksort($outcomes);
        $this->assertSame([
            'run:2026-01#1' => 'reversed',
            'run:2026-01#2' => 'cancelled',
            'run:2026-01#3' => 'already_cancelled',
            'run:2026-01-again#1' => 'already_reversed',
            'run:2026-01-again#2' => 'cancelled',
            'run:2026-01-again#3' => 'cancelled',
            'run:direct#1' => 'cancelled',
        ], $outcomes);
        $this->assertSame([7, 4, 1, 1, 1], [$result->count(), $result->count(CommissionAdjustmentOutcome::Cancelled), $result->count(CommissionAdjustmentOutcome::Reversed), $result->count(CommissionAdjustmentOutcome::AlreadyCancelled), $result->count(CommissionAdjustmentOutcome::AlreadyReversed)]);
        $this->assertSame(
            array_map(static fn ($adjustment): string => $adjustment->commission_id, $result->adjustments),
            collect($result->adjustments)->pluck('commission_id')->sort()->values()->all(),
        );
        $this->assertEquals($unrelatedRows, DB::table('mlm_commissions')->whereIn('id', $unrelated->modelKeys())->orderBy('id')->get()->all());
        $this->assertNotNull($direct->id);
    }

    public function test_processing_again_returns_the_same_adjustments_and_moves_nothing(): void
    {
        $run = $this->monthly($this->unilevel, '2026-01');
        $this->poster()->post($this->approved($this->depth(1, $run)));
        $reversal = $this->reverse($this->entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10'));
        $first = $this->process($reversal);
        $state = [$this->ledgerRows(), DB::table('mlm_commissions')->orderBy('id')->get()->all(), DB::table('mlm_commission_adjustments')->orderBy('id')->get()->all()];

        $again = $this->process($reversal);

        $ids = static fn (CommissionAdjustmentResult $result): array => array_map(static fn (CommissionAdjustment $adjustment): string => $adjustment->id, $result->adjustments);
        $this->assertCount(3, $ids($first));
        $this->assertSame($ids($first), $ids($again));
        $this->assertEquals($state, [$this->ledgerRows(), DB::table('mlm_commissions')->orderBy('id')->get()->all(), DB::table('mlm_commission_adjustments')->orderBy('id')->get()->all()]);
    }

    public function test_a_reversal_with_nothing_to_correct_yet_is_found_again_once_a_commission_exists(): void
    {
        // April: the entry is reversed before any calculation. May: January is
        // calculated — its cutoff precedes the reversal, so it pays.
        $this->travelTo(CarbonImmutable::parse('2026-04-10 12:00:00'));
        $reversal = $this->reverse($this->entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10'));
        $none = $this->process($reversal);

        $this->assertSame([[], $this->entry->id, $reversal->id], [$none->adjustments, $none->originalVolumeEntryId, $none->reversalVolumeEntryId]);
        $this->assertSame(0, CommissionAdjustment::query()->count());

        $this->travelTo(CarbonImmutable::parse('2026-05-02'));
        $run = $this->monthly($this->unilevel, '2026-01');
        $this->assertSame(3, $run->commissions()->count());

        $later = $this->process($reversal);

        $this->assertSame(3, $later->count(CommissionAdjustmentOutcome::Cancelled));
        $this->assertSame([CommissionStatus::Cancelled], $run->commissions()->get()->pluck('status')->unique()->values()->all());

        // June: a direct award for January, after the others were corrected.
        // The reversal is not done for good: the new one is corrected too,
        // and the earlier corrections come back as they were made.
        $this->travelTo(CarbonImmutable::parse('2026-06-03'));
        $direct = $this->monthly($this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan), '2026-01', 'run:direct')->commissions()->sole();

        $last = $this->process($reversal);

        $ids = static fn (CommissionAdjustmentResult $result): array => array_map(static fn (CommissionAdjustment $adjustment): string => $adjustment->id, $result->adjustments);
        $this->assertSame(4, $last->count(CommissionAdjustmentOutcome::Cancelled));
        $this->assertSame($ids($later), array_values(array_intersect($ids($last), $ids($later))));
        $this->assertSame(CommissionStatus::Cancelled, $direct->fresh()->status);
        $this->assertSame(4, CommissionAdjustment::query()->count());
    }

    public function test_the_stored_amount_is_clawed_back_never_recalculated(): void
    {
        // Rounded half-up to 2.895897 from 2.895896651426; the line then grows.
        $this->record($this->members['DIANA'], '1.234567', 'order:P', at: CarbonImmutable::parse('2026-01-15'));
        $proportional = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters(['minimum_quantity' => '0', 'unit_amount' => '2.345678', 'rounding' => 'half_up']), $this->plan);
        $commission = $this->monthly($proportional, '2026-01', 'run:p')->commissions()->where('amount_millionths', 2_895_897)->sole();
        $this->sponsorAt($this->members['ALICE'], $this->members['EVE'], '2026-03-01 00:00:00');
        $source = VolumeEntry::query()->findOrFail($commission->source_id);

        $adjustment = $this->adjustmentOf($this->process($this->reverse($source, 'refund:P', at: CarbonImmutable::parse('2026-04-10'))), $commission);

        $this->assertSame('-2.895897', $adjustment->amount->value());
        $this->assertSame($this->members['CHARLIE']->id, $adjustment->commission->member_id);
        $this->assertSame(1, CommissionAdjustment::query()->count());
    }

    public function test_one_commission_that_cannot_be_corrected_leaves_every_other_uncorrected(): void
    {
        $run = $this->monthly($this->unilevel, '2026-01');
        $last = $run->commissions()->reorder()->orderByDesc('id')->firstOrFail();
        $this->poster()->post($this->approved($last));
        // Only a raw write loses a posted commission's ledger transaction.
        DB::table('mlm_commissions')->where('id', $last->id)->update(['ledger_transaction_id' => null]);
        $state = [$this->ledgerRows(), DB::table('mlm_commissions')->orderBy('id')->get()->all()];

        try {
            $this->process($this->reverse($this->entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10')));
            $this->fail('A commission whose posting is lost was corrected.');
        } catch (InvalidCommissionPosting $exception) {
            $this->assertStringContainsString('its ledger transaction is missing', $exception->getMessage());
        }

        $this->assertEquals($state, [$this->ledgerRows(), DB::table('mlm_commissions')->orderBy('id')->get()->all()]);
        $this->assertSame(0, CommissionAdjustment::query()->count());
    }

    public function test_only_a_stored_reversal_claws_back(): void
    {
        $this->monthly($this->unilevel, '2026-01');

        foreach ([
            'is an original entry, not a reversal' => $this->entry,
            'does not exist' => (new VolumeEntry)->forceFill(['id' => (new VolumeEntry)->newUniqueId()]),
        ] as $reason => $entry) {
            try {
                $this->process($entry);
                $this->fail("Accepted: {$reason}.");
            } catch (InvalidCommissionAdjustment $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
        }

        $this->assertSame(0, CommissionAdjustment::query()->count());
    }

    /**
     * @return array<string, array{Closure(self, VolumeEntry): void, string}>
     */
    public static function corruptReversals(): array
    {
        return [
            'another program' => [static fn (self $test, VolumeEntry $reversal): int => DB::table('mlm_volume_entries')->where('id', $reversal->id)->update(['program_id' => Program::factory()->create()->id]), 'they belong to different programs'],
            'another member' => [static fn (self $test, VolumeEntry $reversal): int => DB::table('mlm_volume_entries')->where('id', $reversal->id)->update(['member_id' => $test->members['EVE']->id]), 'they belong to different members'],
            'another type' => [static fn (self $test, VolumeEntry $reversal): int => DB::table('mlm_volume_entries')->where('id', $reversal->id)->update(['type' => 'returns']), 'they are of different volume types'],
            'not the exact negative' => [static fn (self $test, VolumeEntry $reversal): int => DB::table('mlm_volume_entries')->where('id', $reversal->id)->update(['quantity_millionths' => -149_999_999]), "its quantity is not exactly the original's, negated"],
            'a reversal of a reversal' => [static function (self $test, VolumeEntry $reversal): void {
                $other = $test->reverse($test->sale($test->members['DIANA'], '150', '2026-01-20', 'order:Z'), 'refund:Z', at: CarbonImmutable::parse('2026-04-01'));
                DB::table('mlm_volume_entries')->where('id', $reversal->id)->update(['reversal_of_id' => $other->id]);
            }, 'the entry it names is itself a reversal'],
        ];
    }

    /**
     * @param  Closure(self, VolumeEntry): void  $corrupt
     */
    #[DataProvider('corruptReversals')]
    public function test_a_stored_reversal_that_does_not_agree_with_its_original_corrects_nothing(Closure $corrupt, string $reason): void
    {
        $this->monthly($this->unilevel, '2026-01');
        $reversal = $this->reverse($this->entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10'));
        $corrupt($this, $reversal);
        $state = [$this->ledgerRows(), DB::table('mlm_commissions')->orderBy('id')->get()->all()];

        try {
            $this->process($reversal);
            $this->fail('A corrupt reversal corrected commissions.');
        } catch (InvalidCommissionAdjustment $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        $this->assertEquals($state, [$this->ledgerRows(), DB::table('mlm_commissions')->orderBy('id')->get()->all()]);
        $this->assertSame(0, CommissionAdjustment::query()->count());
    }

    public function test_provenance_crossing_programs_is_refused_not_skipped(): void
    {
        $commission = $this->depth(2, $this->monthly($this->unilevel, '2026-01'));
        $elsewhere = Program::factory()->create();
        DB::table('mlm_commissions')->where('id', $commission->id)->update(['program_id' => $elsewhere->id]);
        $state = DB::table('mlm_commissions')->orderBy('id')->get()->all();

        try {
            $this->process($this->reverse($this->entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10')));
            $this->fail('Provenance crossing programs was processed.');
        } catch (InvalidCommissionAdjustment $exception) {
            $this->assertStringContainsString("Commission [{$commission->id}] of program [{$elsewhere->id}] names volume entry [{$this->entry->id}]", $exception->getMessage());
        }

        $this->assertEquals($state, DB::table('mlm_commissions')->orderBy('id')->get()->all());
        $this->assertSame(0, CommissionAdjustment::query()->count());
    }

    public function test_the_stored_reversal_decides_not_the_instance(): void
    {
        $this->monthly($this->unilevel, '2026-01');
        $reversal = $this->reverse($this->entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10 08:00:00'));
        $other = $this->sale($this->members['DIANA'], '150', '2026-01-20', 'order:Z');
        $reversal->forceFill(['program_id' => Program::factory()->create()->id, 'reversal_of_id' => $other->id, 'effective_at' => '2030-01-01 00:00:00']);

        $result = $this->process($reversal);

        $this->assertSame($this->entry->id, $result->originalVolumeEntryId);
        $this->assertSame(['2026-04-10 08:00:00'], collect($result->adjustments)->map(static fn ($adjustment): string => $adjustment->occurred_at->format('Y-m-d H:i:s'))->unique()->values()->all());
        $this->assertSame(3, $result->count());
    }

    public function test_adjustments_are_read_only_through_eloquent_and_related_to_their_commission(): void
    {
        $commission = $this->depth(1, $this->monthly($this->unilevel, '2026-01'));
        $adjustment = $this->adjustmentOf($this->process($this->reverse($this->entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10'))), $commission);

        $this->assertTrue($adjustment->commission->is($commission));
        $this->assertTrue($adjustment->program->is($this->plan->program));
        $this->assertTrue($commission->adjustments()->sole()->is($adjustment));
        $this->assertSame(3, $this->plan->program->commissionAdjustments()->count());

        foreach ([
            static fn () => $adjustment->forceFill(['amount_millionths' => 0])->save(),
            static fn () => $adjustment->delete(),
            static fn () => (new CommissionAdjustment)->forceFill($adjustment->only(['program_id', 'commission_id', 'type', 'source_type', 'amount_millionths', 'occurred_at', 'outcome']) + ['source_id' => 'x', 'trace' => '[]'])->save(),
        ] as $write) {
            try {
                $write();
                $this->fail('An adjustment was written through its model.');
            } catch (ImmutableCalculationRecord $exception) {
                $this->assertStringContainsString('CommissionAdjustmentEngine', $exception->getMessage());
            }
        }

        $this->assertSame(3, CommissionAdjustment::query()->count());
    }

    public function test_a_correction_creates_no_run_and_no_commission(): void
    {
        $this->monthly($this->unilevel, '2026-01');
        $counts = [CalculationRun::query()->count(), Commission::query()->count()];

        $this->process($this->reverse($this->entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10')));

        $this->assertSame($counts, [CalculationRun::query()->count(), Commission::query()->count()]);
        $this->assertSame(0, Commission::query()->where('amount_millionths', '<', 0)->count());
    }

    public function test_the_engine_corrects_through_the_lifecycle_and_poster_alone(): void
    {
        $constructor = (new \ReflectionClass(CommissionAdjustmentEngine::class))->getConstructor();

        // No genealogy, strategy, plan, conversion or ledger writer: a
        // correction recalculates nothing and moves money only through the
        // commission's own services.
        $this->assertSame(
            [CommissionLifecycle::class, CommissionPoster::class],
            array_map(static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(), $constructor?->getParameters() ?? []),
        );
    }

    private function process(VolumeEntry $reversal): CommissionAdjustmentResult
    {
        return $this->app->make(CommissionAdjustmentEngine::class)->processVolumeReversal($reversal);
    }

    private function depth(int $depth, CalculationRun $run): Commission
    {
        return $run->commissions()->where('candidate_key', "volume-entry:{$this->entry->id}:depth:{$depth}")->sole();
    }

    private function adjustmentOf(CommissionAdjustmentResult $result, Commission $commission): CommissionAdjustment
    {
        foreach ($result->adjustments as $adjustment) {
            if ($adjustment->commission_id === $commission->id) {
                return $adjustment;
            }
        }

        $this->fail("No adjustment for commission [{$commission->id}].");
    }

    /**
     * What a calculation stored, which no correction may change.
     *
     * @return array<string, mixed>
     */
    private function calculatedFacts(Commission $commission): array
    {
        $row = (array) DB::table('mlm_commissions')->where('id', $commission->id)->first();

        return array_intersect_key($row, array_flip(['calculation_run_id', 'program_id', 'member_id', 'candidate_key', 'currency', 'amount_millionths', 'earned_at', 'trace', 'source_type', 'source_id']));
    }
}
