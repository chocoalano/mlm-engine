<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Calculation\CalculationContext;
use PandaBear\Mlm\Calculation\CalculationEngine;
use PandaBear\Mlm\Commission\CommissionAdjustmentEngine;
use PandaBear\Mlm\Commission\CommissionAdjustmentOutcome;
use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionCandidate;
use PandaBear\Mlm\Commission\CommissionLifecycle;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Exceptions\CommissionPeriodNotReleased;
use PandaBear\Mlm\Exceptions\ConflictingCommissionPeriod;
use PandaBear\Mlm\Exceptions\CorruptCommissionPeriod;
use PandaBear\Mlm\Exceptions\FinalizedCommissionPeriod;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Exceptions\InvalidCommissionPeriod;
use PandaBear\Mlm\Exceptions\InvalidCommissionPeriodTransition;
use PandaBear\Mlm\Models\CalculationBatch;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionAdjustment;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Models\CommissionPeriodRun;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Period\CommissionPeriodCalculator;
use PandaBear\Mlm\Period\CommissionPeriodFinalizer;
use PandaBear\Mlm\Period\CommissionPeriodManager;
use PandaBear\Mlm\Period\CommissionPeriodReleaser;
use PandaBear\Mlm\Period\CommissionPeriodResult;
use PandaBear\Mlm\Period\CommissionPeriodStatus;
use PandaBear\Mlm\Period\CommissionPeriodTotals;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Tests\Concerns\BuildsBinaryPairing;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsFixedCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use RuntimeException;

/**
 * Commission periods (ADR-029): a program's non-overlapping calculation,
 * review and release boundary. Calculating closes its range to new business
 * entries; finalizing holds its approved commissions; releasing makes them
 * available; only then are they posted — and none of those steps moves
 * money.
 */
final class CommissionPeriodTest extends DatabaseTestCase
{
    use BuildsBinaryPairing;
    use BuildsCommissions;
    use BuildsFixedCommissions;
    use BuildsGenealogies;
    use BuildsLedgers;
    use BuildsPlanDefinitions;
    use RecordsVolume;

    private Program $program;

    /**
     * @var array<string, Member>
     */
    private array $members;

    private LedgerAccount $source;

    protected function setUp(): void
    {
        parent::setUp();

        // Sponsors: ALICE > BOB > CHARLIE. Binary: P > L left, P > R right.
        $this->program = Program::factory()->create();
        $this->members = $this->members($this->program, 'ALICE', 'BOB', 'CHARLIE', 'P', 'L', 'R');
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sponsorAt($this->members['CHARLIE'], $this->members['BOB'], '2026-01-01 00:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->binary()->place($this->members['L'], $this->members['P'], BinarySide::Left);
        $this->binary()->place($this->members['R'], $this->members['P'], BinarySide::Right);
        $this->travelBack();
        $this->source = $this->systemAccounts()->openSystemAccount($this->program, 'IDR', 'commission.payable');
    }

    public function test_a_period_is_created_for_the_active_version_once_under_its_key(): void
    {
        $version = $this->activeVersion(['direct' => 'direct-sponsor.fixed']);

        $period = $this->period($version);

        $this->assertSame([CommissionPeriodStatus::Open, $this->program->id, $version->id, $this->source->id, '2026-01-01 00:00:00', '2026-02-01 00:00:00', '2026-02-15 00:00:00'], [
            $period->status, $period->program_id, $period->plan_version_id, $period->source_ledger_account_id,
            $period->from_at->format('Y-m-d H:i:s'), $period->until_at->format('Y-m-d H:i:s'), $period->release_at->format('Y-m-d H:i:s'),
        ]);
        $this->assertSame([null, null, null, null], [$period->input_closed_at, $period->calculated_at, $period->finalized_at, $period->released_at]);
        $this->assertTrue($period->is($this->period($version)));
        $this->assertSame(1, CommissionPeriod::query()->count());
        $this->assertTrue($period->planVersion->is($version));
        $this->assertTrue($period->sourceAccount->is($this->source));
    }

    public function test_the_same_key_with_other_facts_is_refused(): void
    {
        $version = $this->activeVersion(['direct' => 'direct-sponsor.fixed']);
        $this->period($version);
        $other = $this->activeVersion(['direct' => 'direct-sponsor.fixed']);

        foreach ([
            'plan version' => fn () => $this->period($other),
            'source account' => fn () => $this->period($version, source: $this->systemAccounts()->openSystemAccount($this->program, 'IDR', 'other.payable')),
            'from' => fn () => $this->period($version, from: '2026-01-02'),
            'until' => fn () => $this->period($version, until: '2026-02-02'),
            'release at' => fn () => $this->period($version, release: '2026-02-16'),
        ] as $fact => $request) {
            try {
                $request();
                $this->fail("A key was reused for another {$fact}.");
            } catch (ConflictingCommissionPeriod $exception) {
                $this->assertStringContainsString("this request differs in {$fact}", $exception->getMessage());
            }
        }

        $this->assertSame(1, CommissionPeriod::query()->count());
    }

    public function test_a_period_needs_a_real_range_a_release_after_it_and_the_programs_active_funded_version(): void
    {
        $version = $this->activeVersion(['direct' => 'direct-sponsor.fixed']);
        $validated = $this->version(['direct' => 'direct-sponsor.fixed'], activate: false);
        $foreign = $this->systemAccounts()->openSystemAccount(Program::factory()->create(), 'IDR', 'commission.payable');

        foreach ([
            'is empty or reversed' => fn () => $this->period($version, from: '2026-02-01'),
            'is empty or reversed.' => fn () => $this->period($version, from: '2026-03-01'),
            'is before 2026-02-01 00:00:00' => fn () => $this->period($version, release: '2026-01-31 23:59:59'),
            'is validated; a commission period is created for the program\'s active plan version' => fn () => $this->period($validated),
            'belongs to program' => fn () => $this->period($version, source: $foreign),
            'is funded from "commission.payable" in IDR, not "other.payable" in IDR' => fn () => $this->period($version, source: $this->systemAccounts()->openSystemAccount($this->program, 'IDR', 'other.payable')),
            'does not belong to program' => fn () => $this->app->make(CommissionPeriodManager::class)->create(Program::factory()->create(), $version, $this->source, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-02-01'), 'x'),
        ] as $reason => $request) {
            try {
                $request();
                $this->fail("An invalid period was created: {$reason}.");
            } catch (InvalidCommissionPeriod $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
        }

        $this->assertSame(0, CommissionPeriod::query()->count());
        // A release at the very end is fine.
        $this->assertSame('2026-02-01 00:00:00', $this->period($version, release: '2026-02-01')->release_at->format('Y-m-d H:i:s'));
    }

    public function test_a_programs_periods_never_overlap_but_may_touch_or_leave_gaps(): void
    {
        $version = $this->activeVersion(['direct' => 'direct-sponsor.fixed']);
        $this->period($version);
        $this->period($version, from: '2026-02-01', until: '2026-03-01', release: '2026-03-01', key: 'feb');
        $this->period($version, from: '2026-04-01', until: '2026-05-01', release: '2026-05-01', key: 'apr');

        try {
            $this->period($version, from: '2026-02-28 23:59:59', until: '2026-03-15', release: '2026-03-15', key: 'overlap');
            $this->fail('Overlapping periods were created.');
        } catch (InvalidCommissionPeriod $exception) {
            $this->assertStringContainsString('a program\'s periods never overlap', $exception->getMessage());
        }

        // Another program's timeline is its own.
        $other = Program::factory()->create();
        $plan = Plan::factory()->for($other)->create();
        $draft = $this->draft($plan);
        $this->editor()->addComponent($draft, 'direct', 'commission.strategy', 'Direct', $this->commissionParameters(['strategy' => 'direct-sponsor.fixed', 'parameters' => $this->parametersOf('direct-sponsor.fixed')]));
        $this->activate($draft);
        $account = $this->systemAccounts()->openSystemAccount($other, 'IDR', 'commission.payable');
        $this->app->make(CommissionPeriodManager::class)->create($other, PlanVersion::query()->findOrFail($draft->id), $account, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-02-01'), 'period:2026-01');

        $this->assertSame([3, 1], [CommissionPeriod::query()->where('program_id', $this->program->id)->count(), CommissionPeriod::query()->where('program_id', $other->id)->count()]);
    }

    public function test_one_component_is_calculated_by_the_calculation_engine_under_a_period_key(): void
    {
        $this->sale($this->members['CHARLIE'], '150', '2026-01-10', 'order:1');
        $period = $this->period($this->activeVersion(['direct' => 'direct-sponsor.fixed']));

        $result = $this->calculate($period);

        $run = $result->calculationRuns()[0];
        $this->assertSame([CommissionPeriodStatus::Calculated, 1, 0], [$result->period->status, count($result->runs), CalculationBatch::query()->count()]);
        $this->assertNotNull($result->period->calculated_at);
        $this->assertSame(["period:{$period->id}:{$run->plan_component_id}", '2026-01-01 00:00:00', '2026-02-01 00:00:00', $this->source->id], [$run->idempotency_key, $run->from_at->format('Y-m-d H:i:s'), $run->until_at->format('Y-m-d H:i:s'), $run->source_ledger_account_id]);
        $this->assertSame([['BOB', '10']], $this->awarded($result->commissions()));
        $this->assertTrue($run->periodRun?->period->is($period));
    }

    public function test_several_components_are_calculated_as_the_periods_hybrid_batch(): void
    {
        $this->sale($this->members['CHARLIE'], '150', '2026-01-10', 'order:1');
        $version = $this->activeVersion(['unilevel' => 'unilevel.fixed', 'direct' => 'direct-sponsor.fixed'], ranks: true);
        $period = $this->period($version);

        $result = $this->calculate($period);

        $this->assertSame("period:{$period->id}:hybrid", CalculationBatch::query()->sole()->idempotency_key);
        $this->assertSame(['unilevel', 'direct'], array_map(static fn ($component): string => $component->key, $result->components()));
        $this->assertSame([1, 2], array_map(static fn (CommissionPeriodRun $run): int => $run->position, $result->runs));
        $this->assertSame([['BOB', '5'], ['ALICE', '2']], $this->awarded($result->commissionsOf($result->calculationRuns()[0])));
        $this->assertSame([['BOB', '10']], $this->awarded($result->commissionsOf($result->components()[1])));
        $this->assertSame(2, CalculationRun::query()->count());
    }

    public function test_a_version_without_commission_components_is_not_calculated(): void
    {
        $period = $this->period($this->activeVersion([], ranks: true));

        try {
            $this->calculate($period);
            $this->fail('A period without commission components was calculated.');
        } catch (InvalidCommissionPeriod $exception) {
            $this->assertStringContainsString('has no commission component', $exception->getMessage());
        }

        $this->assertSame(CommissionPeriodStatus::Open, $period->refresh()->status);
    }

    public function test_a_failed_calculation_leaves_the_period_open_with_its_input_closed_and_resumes(): void
    {
        $strategy = $this->scriptedStrategy();
        $version = $this->activeVersion(['one' => 'test.scripted', 'two' => 'test.scripted']);
        $failing = $version->components()->where('key', 'two')->value('id');
        $strategy->script = function (CommissionCalculationContext $context) use (&$failing): iterable {
            if ($context->planComponentId === $failing) {
                throw new RuntimeException('Component two failed.');
            }

            return [new CommissionCandidate('scripted', $this->members['ALICE'], '1', CarbonImmutable::parse('2026-01-15'))];
        };
        $period = $this->period($version);

        try {
            $this->calculate($period);
            $this->fail('A failing component calculated the period.');
        } catch (RuntimeException) {
        }

        $period->refresh();
        $this->assertSame(CommissionPeriodStatus::Open, $period->status);
        $this->assertNotNull($period->input_closed_at);
        $this->assertSame(0, CommissionPeriodRun::query()->count());

        // Its range takes nothing new while it is being calculated.
        try {
            $this->sale($this->members['CHARLIE'], '150', '2026-01-20', 'order:late');
            $this->fail('An entry was recorded into a range being calculated.');
        } catch (FinalizedCommissionPeriod $exception) {
            $this->assertStringContainsString('which is being calculated and takes no new entry', $exception->getMessage());
        }

        $failing = null;
        $result = $this->calculate($period);

        $this->assertSame([CommissionPeriodStatus::Calculated, 2, 2, 3], [$result->period->status, CalculationRun::query()->count(), Commission::query()->count(), $strategy->calculations]);
        $this->assertTrue($result->period->input_closed_at?->equalTo($period->input_closed_at));
    }

    public function test_a_calculated_period_is_returned_as_stored_and_a_wrong_link_is_refused(): void
    {
        $this->sale($this->members['CHARLIE'], '150', '2026-01-10', 'order:1');
        $period = $this->period($this->activeVersion(['direct' => 'direct-sponsor.fixed', 'unilevel' => 'unilevel.fixed']));
        $first = $this->calculate($period);
        $writes = 0;
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1) {
                $writes++;
            }
        });

        $again = $this->calculate($period);

        $this->assertSame(0, $writes);
        $this->assertSame($this->ids($first->calculationRuns()), $this->ids($again->calculationRuns()));
        DB::getEventDispatcher()?->forget(QueryExecuted::class);

        // A raw write: the first component linked to the second's run.
        $runs = CommissionPeriodRun::query()->orderBy('position')->get();
        DB::table('mlm_commission_period_runs')->where('id', $runs[1]->id)->delete();
        DB::table('mlm_commission_period_runs')->where('id', $runs[0]->id)->update(['calculation_run_id' => $runs[1]->calculation_run_id]);

        $this->expectException(CorruptCommissionPeriod::class);

        $this->calculate($period);
    }

    public function test_a_binary_component_moves_its_state_once(): void
    {
        $this->sale($this->members['L'], '100', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $version = $this->activeVersion(['binary' => 'binary.pairing.fixed', 'unilevel' => 'unilevel.fixed']);
        $period = $this->period($version);

        $result = $this->calculate($period);
        $state = $this->pairingState();
        $this->calculate($period);

        $this->assertSame([['P', '100']], $this->awarded($result->commissionsOf($result->calculationRuns()[0])));
        $this->assertEquals($state, $this->pairingState());
        $this->assertSame('2026-02-01 00:00:00', (string) DB::table('mlm_binary_pairing_cursors')->value('through_at'));
    }

    public function test_calculating_closes_the_range_to_new_entries_but_not_later_reversals_of_its_entries(): void
    {
        $version = $this->activeVersion(['direct' => 'direct-sponsor.fixed']);
        $period = $this->period($version);
        // Open: entries and reversals are recorded in its range.
        $sale = $this->sale($this->members['CHARLIE'], '150', '2026-01-10', 'order:1');
        $this->reverse($this->sale($this->members['CHARLIE'], '150', '2026-01-11', 'order:2'), 'refund:2', at: CarbonImmutable::parse('2026-01-12'));

        $this->calculate($period);

        foreach ([
            'an entry at its start' => fn () => $this->sale($this->members['CHARLIE'], '1', '2026-01-01 00:00:00', 'order:start'),
            'an entry just before its end' => fn () => $this->sale($this->members['CHARLIE'], '1', '2026-01-31 23:59:59', 'order:end'),
            'a reversal inside it' => fn () => $this->reverse($sale, 'refund:inside', at: CarbonImmutable::parse('2026-01-20')),
        ] as $case => $write) {
            try {
                $write();
                $this->fail("Closed range accepted {$case}.");
            } catch (FinalizedCommissionPeriod $exception) {
                $this->assertStringContainsString('which is calculated and takes no new entry', $exception->getMessage());
            }
        }

        // Its end is outside it, and a later reversal of its entry is the later range's.
        $this->assertSame('2026-02-01 00:00:00', $this->sale($this->members['CHARLIE'], '1', '2026-02-01 00:00:00', 'order:after')->effective_at->format('Y-m-d H:i:s'));
        $reversal = $this->reverse($sale, 'refund:later', at: CarbonImmutable::parse('2026-02-05'));
        $this->assertSame($sale->id, $reversal->reversal_of_id);
        // A replay of an entry already recorded in the range is still a replay.
        $this->assertTrue($sale->is($this->sale($this->members['CHARLIE'], '150', '2026-01-10', 'order:1')));
    }

    public function test_finalizing_holds_every_approved_commission_and_needs_every_commission_reviewed(): void
    {
        $this->sale($this->members['CHARLIE'], '150', '2026-01-10', 'order:1');
        $period = $this->period($this->activeVersion(['direct' => 'direct-sponsor.fixed', 'unilevel' => 'unilevel.fixed']));

        try {
            $this->finalizer()->finalize($period);
            $this->fail('An open period was finalized.');
        } catch (InvalidCommissionPeriodTransition $exception) {
            $this->assertStringContainsString('is open; it is finalized only when it is calculated', $exception->getMessage());
        }

        [$direct, $unilevelBob, $unilevelAlice] = $this->ordered($this->calculate($period)->commissions());
        $this->approved($direct);
        $this->commissionLifecycle()->markPending($unilevelBob);
        $ledger = $this->ledgerRows();

        try {
            $this->finalizer()->finalize($period);
            $this->fail('A period with unreviewed commissions was finalized.');
        } catch (InvalidCommissionPeriodTransition $exception) {
            $this->assertStringContainsString('but it has 1 calculated, 1 pending', $exception->getMessage());
        }

        // Nothing moved: all or none.
        $this->assertSame([CommissionStatus::Approved, CommissionStatus::Pending, CommissionStatus::Calculated, CommissionPeriodStatus::Calculated], [$direct->refresh()->status, $unilevelBob->refresh()->status, $unilevelAlice->refresh()->status, $period->refresh()->status]);

        $this->commissionLifecycle()->approve($unilevelBob);
        $this->commissionLifecycle()->cancel($unilevelAlice);
        $this->travelTo(CarbonImmutable::parse('2026-02-03 09:00:00'));
        $finalized = $this->finalizer()->finalize($period);

        $this->assertSame([CommissionPeriodStatus::Finalized, '2026-02-03 09:00:00'], [$finalized->status, $finalized->finalized_at?->format('Y-m-d H:i:s')]);
        $this->assertSame([CommissionStatus::Held, CommissionStatus::Held, CommissionStatus::Cancelled], [$direct->refresh()->status, $unilevelBob->refresh()->status, $unilevelAlice->refresh()->status]);
        $this->assertSame(['2026-02-03 09:00:00', null], [$direct->held_at?->format('Y-m-d H:i:s'), $unilevelAlice->held_at]);
        $this->assertSame($ledger, $this->ledgerRows());
        $this->assertTrue($finalized->is($this->finalizer()->finalize($period)));
        $this->assertTrue($finalized->finalized_at?->equalTo($period->refresh()->finalized_at));
    }

    public function test_releasing_makes_held_commissions_available_no_earlier_than_the_release_moment(): void
    {
        $this->sale($this->members['CHARLIE'], '150', '2026-01-10', 'order:1');
        $period = $this->period($this->activeVersion(['direct' => 'direct-sponsor.fixed', 'unilevel' => 'unilevel.fixed']));
        [$direct, $bob, $alice] = $this->ordered($this->calculate($period)->commissions());

        try {
            $this->releaser()->release($period, CarbonImmutable::parse('2026-03-01'));
            $this->fail('A calculated period was released.');
        } catch (InvalidCommissionPeriodTransition $exception) {
            $this->assertStringContainsString('is calculated; it is released only when it is finalized', $exception->getMessage());
        }

        $this->approved($direct);
        $this->approved($bob);
        $this->commissionLifecycle()->cancel($alice);
        $this->finalizer()->finalize($period);
        $ledger = $this->ledgerRows();

        try {
            $this->releaser()->release($period, CarbonImmutable::parse('2026-02-14 23:59:59'));
            $this->fail('A period was released early.');
        } catch (InvalidCommissionPeriodTransition $exception) {
            $this->assertStringContainsString('is released no earlier than 2026-02-15 00:00:00', $exception->getMessage());
        }

        $released = $this->releaser()->release($period, CarbonImmutable::parse('2026-02-15 00:00:00'));

        $this->assertSame([CommissionPeriodStatus::Released, '2026-02-15 00:00:00'], [$released->status, $released->released_at?->format('Y-m-d H:i:s')]);
        $this->assertSame([CommissionStatus::Available, CommissionStatus::Available, CommissionStatus::Cancelled], [$direct->refresh()->status, $bob->refresh()->status, $alice->refresh()->status]);
        $this->assertSame(['2026-02-15 00:00:00', null], [$direct->available_at?->format('Y-m-d H:i:s'), $alice->available_at]);
        $this->assertSame($ledger, $this->ledgerRows());

        // Releasing again, later, changes nothing.
        $again = $this->releaser()->release($period, CarbonImmutable::parse('2026-06-01'));
        $this->assertSame(['2026-02-15 00:00:00', '2026-02-15 00:00:00'], [$again->released_at?->format('Y-m-d H:i:s'), $direct->refresh()->available_at?->format('Y-m-d H:i:s')]);

        // Its range stays closed.
        $this->expectException(FinalizedCommissionPeriod::class);
        $this->sale($this->members['CHARLIE'], '1', '2026-01-20', 'order:late');
    }

    public function test_a_period_commission_is_posted_only_when_available_in_a_released_period(): void
    {
        $this->sale($this->members['CHARLIE'], '150', '2026-01-10', 'order:1');
        $period = $this->period($this->activeVersion(['direct' => 'direct-sponsor.fixed']));
        $commission = $this->approved($this->calculate($period)->commissions()[0]);

        $this->assertRefusedPosting($commission, 'is approved and the period is calculated');
        $this->finalizer()->finalize($period);
        $this->assertRefusedPosting($commission, 'is held and the period is finalized');

        // A raw write: available, though the period is only finalized.
        DB::table('mlm_commissions')->where('id', $commission->id)->update(['status' => 'available']);
        $this->assertRefusedPosting($commission, 'is available and the period is finalized');
        DB::table('mlm_commissions')->where('id', $commission->id)->update(['status' => 'held']);

        $this->releaser()->release($period, CarbonImmutable::parse('2026-02-15'));
        $posted = $this->poster()->post($commission);

        $this->assertSame([CommissionStatus::Posted, '10', '10'], [$posted->status, $posted->postedAmount?->value(), $this->walletOf('BOB')]);

        // A run calculated on its own still posts straight from approved.
        $standalone = $this->app->make(CalculationEngine::class)->calculate($period->planVersion->components()->sole(), new CalculationContext(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-01'), 'standalone'));
        $this->assertSame(CommissionStatus::Posted, $this->poster()->post($this->approved($standalone->commissions()->sole()))->status);
    }

    public function test_a_period_run_not_yet_linked_is_the_periods_all_the_same(): void
    {
        $this->sale($this->members['CHARLIE'], '150', '2026-01-10', 'order:1');
        $version = $this->activeVersion(['direct' => 'direct-sponsor.fixed']);
        $period = $this->period($version);
        // As a crash would leave it: the run is committed under the period's key, unlinked.
        $run = $this->app->make(CalculationEngine::class)->calculate($version->components()->sole(), new CalculationContext($period->from_at, $period->until_at, "period:{$period->id}:{$version->components()->sole()->id}"));

        $this->assertRefusedPosting($this->approved($run->commissions()->sole()), 'of commission period ['.$period->id.']');

        $this->assertSame($run->id, $this->calculate($period)->calculationRuns()[0]->id);
    }

    public function test_a_source_reversal_cancels_a_held_or_available_commission_and_moves_no_money(): void
    {
        $sale = $this->sale($this->members['CHARLIE'], '150', '2026-01-10', 'order:1');
        $period = $this->period($this->activeVersion(['direct' => 'direct-sponsor.fixed', 'unilevel' => 'unilevel.fixed']));

        foreach ($this->calculate($period)->commissions() as $commission) {
            $this->approved($commission);
        }

        $this->finalizer()->finalize($period);
        [$direct] = $this->ordered(Commission::query()->get()->all());
        // One is made available by hand of the period, the rest stay held.
        $this->releaser()->release($period, CarbonImmutable::parse('2026-02-15'));
        DB::table('mlm_commissions')->where('id', '!=', $direct->id)->update(['status' => 'held']);
        $ledger = $this->ledgerRows();

        $result = $this->app->make(CommissionAdjustmentEngine::class)->processVolumeReversal($this->reverse($sale, 'refund:1', at: CarbonImmutable::parse('2026-02-20')));

        $this->assertSame(3, $result->count(CommissionAdjustmentOutcome::Cancelled));
        $this->assertSame([CommissionStatus::Cancelled], Commission::query()->pluck('status')->unique()->values()->all());
        $this->assertSame($ledger, $this->ledgerRows());
    }

    public function test_a_binary_correction_of_a_held_commission_blocks_finalizing_until_processed_and_posting_pays_what_is_left(): void
    {
        // One pair: left l1 30 + l2 70, right r1 100 — paying 100.
        $small = $this->sale($this->members['L'], '30', '2026-01-10', 'l1');
        $this->sale($this->members['L'], '70', '2026-01-11', 'l2');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $version = $this->activeVersion(['binary' => 'binary.pairing.fixed']);
        $period = $this->period($version);
        $commission = $this->approved($this->calculate($period)->commissions()[0]);
        // February, outside the period: l1 is refunded, and the next pairing run undoes 30 of the pair.
        $reversal = $this->reverse($small, 'refund:l1', at: CarbonImmutable::parse('2026-02-05'));
        $this->app->make(CalculationEngine::class)->calculate($version->components()->sole(), new CalculationContext(CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-03-01'), 'binary:feb'));

        try {
            $this->finalizer()->finalize($period);
            $this->fail('A period with an unresolved binary correction was finalized.');
        } catch (InvalidCommissionPeriodTransition $exception) {
            $this->assertStringContainsString("binary corrections of commissions [{$commission->id}] have no financial adjustment yet", $exception->getMessage());
        }

        $this->assertSame(CommissionStatus::Approved, $commission->refresh()->status);

        // Processed while approved, then held: the share is recorded, nothing moves.
        $this->assertSame(['-30', 'recorded'], $this->adjusted($this->app->make(CommissionAdjustmentEngine::class)->processBinaryReversal($reversal)->adjustments[0]));
        $this->finalizer()->finalize($period);
        $this->releaser()->release($period, CarbonImmutable::parse('2026-02-15'));
        $posted = $this->poster()->post($commission);

        $this->assertSame(['100', '70', '70'], [$posted->amount->value(), $posted->postedAmount?->value(), $this->walletOf('P')]);
    }

    public function test_binary_corrections_of_held_and_available_commissions_record_part_and_cancel_the_whole(): void
    {
        $small = $this->sale($this->members['L'], '30', '2026-01-10', 'l1');
        $this->sale($this->members['L'], '70', '2026-01-11', 'l2');
        $right = $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $version = $this->activeVersion(['binary' => 'binary.pairing.fixed']);
        $period = $this->period($version);
        $commission = $this->approved($this->calculate($period)->commissions()[0]);
        $this->finalizer()->finalize($period);
        $binary = $version->components()->sole();
        $ledger = $this->ledgerRows();

        // Held: 30 of the pair is undone — recorded, still held.
        $partial = $this->reverse($small, 'refund:l1', at: CarbonImmutable::parse('2026-02-05'));
        $this->app->make(CalculationEngine::class)->calculate($binary, new CalculationContext(CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-03-01'), 'binary:feb'));
        $this->assertSame(['-30', 'recorded'], $this->adjusted($this->app->make(CommissionAdjustmentEngine::class)->processBinaryReversal($partial)->adjustments[0]));
        $this->assertSame(CommissionStatus::Held, $commission->refresh()->status);

        // Available: the rest is undone — cancelled.
        $this->releaser()->release($period, CarbonImmutable::parse('2026-02-15'));
        $this->assertSame(CommissionStatus::Available, $commission->refresh()->status);
        $whole = $this->reverse($right, 'refund:r1', at: CarbonImmutable::parse('2026-03-05'));
        $this->app->make(CalculationEngine::class)->calculate($binary, new CalculationContext(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-04-01'), 'binary:mar'));
        $this->assertSame(['-70', 'cancelled'], $this->adjusted($this->app->make(CommissionAdjustmentEngine::class)->processBinaryReversal($whole)->adjustments[0]));

        $this->assertSame([CommissionStatus::Cancelled, $ledger], [$commission->refresh()->status, $this->ledgerRows()]);
    }

    public function test_period_totals_are_exact(): void
    {
        $sale = $this->sale($this->members['CHARLIE'], '150', '2026-01-10', 'order:1');
        $this->sale($this->members['BOB'], '150', '2026-01-11', 'order:2');
        $period = $this->period($this->activeVersion(['direct' => 'direct-sponsor.fixed', 'unilevel' => 'unilevel.fixed']));

        foreach ($this->calculate($period)->commissions() as $commission) {
            $this->approved($commission);
        }

        // CHARLIE's sale pays BOB 10 + 5, ALICE 2; BOB's pays ALICE 10 + 5.
        $this->finalizer()->finalize($period);
        $this->releaser()->release($period, CarbonImmutable::parse('2026-02-15'));
        $bobs = Commission::query()->where('member_id', $this->members['ALICE']->id)->where('amount_millionths', 10_000_000)->sole();
        $this->poster()->post($bobs);
        $this->app->make(CommissionAdjustmentEngine::class)->processVolumeReversal($this->reverse($sale, 'refund:1', at: CarbonImmutable::parse('2026-02-20')));

        $totals = CommissionPeriodTotals::of($period);

        $this->assertSame(['32', '-17', '15', '10'], [$totals->calculated->value(), $totals->adjustments->value(), $totals->net->value(), $totals->posted->value()]);
        $this->assertSame(['available' => 1, 'cancelled' => 3, 'posted' => 1], $totals->counts);
    }

    public function test_the_period_rows_are_written_by_the_services_alone_and_the_lifecycle_offers_no_shortcut(): void
    {
        $this->sale($this->members['CHARLIE'], '150', '2026-01-10', 'order:1');
        $period = $this->period($this->activeVersion(['direct' => 'direct-sponsor.fixed']));
        $this->calculate($period);
        $run = CommissionPeriodRun::query()->sole();

        foreach ([
            static fn () => CommissionPeriod::query()->forceCreate([]),
            static fn () => $period->forceFill(['status' => 'released'])->save(),
            static fn () => $period->delete(),
            static fn () => CommissionPeriodRun::query()->forceCreate([]),
            static fn () => $run->forceFill(['position' => 2])->save(),
            static fn () => $run->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A period row was written through Eloquent.');
            } catch (ImmutableCalculationRecord) {
            }
        }

        $this->assertEqualsCanonicalizing(['markPending', 'approve', 'cancel'], array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(CommissionLifecycle::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        ));
        $this->assertSame([CommissionStatus::Held, CommissionStatus::Posted, CommissionStatus::Cancelled], CommissionStatus::Approved->nextSteps());
        $this->assertSame([CommissionStatus::Available, CommissionStatus::Cancelled], CommissionStatus::Held->nextSteps());
        $this->assertSame([CommissionStatus::Posted, CommissionStatus::Cancelled], CommissionStatus::Available->nextSteps());
    }

    /**
     * A validated — and, unless asked otherwise, activated — version of a
     * new plan of the program with these commission components.
     *
     * @param  array<string, string>  $components
     */
    private function version(array $components, bool $ranks = false, bool $activate = true): PlanVersion
    {
        $draft = $this->draft(Plan::factory()->for($this->program)->create());

        foreach ($components as $key => $strategy) {
            $this->editor()->addComponent($draft, $key, 'commission.strategy', ucfirst($key), $this->commissionParameters(['strategy' => $strategy, 'parameters' => $this->parametersOf($strategy)]));
        }

        if ($ranks) {
            $ladder = $this->editor()->addComponent($draft, 'career-ranks', 'rank.ladder', 'Career Ranks', [], 0);
            $this->editor()->addRule($ladder, 'bronze', 'Bronze', RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100')), 10);
        }

        $activate ? $this->activate($draft) : $this->lifecycle()->markValidated($draft);

        return PlanVersion::query()->findOrFail($draft->id);
    }

    /**
     * @param  array<string, string>  $components
     */
    private function activeVersion(array $components, bool $ranks = false): PlanVersion
    {
        return $this->version($components, $ranks);
    }

    private function activate(PlanVersion $draft): void
    {
        $this->lifecycle()->markValidated($draft);
        $this->lifecycle()->publish($draft->refresh());
        $this->lifecycle()->activate($draft->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function parametersOf(string $strategy): array
    {
        $source = ['volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '1'];

        return match ($strategy) {
            'direct-sponsor.fixed' => [...$source, 'amount' => '10'],
            'unilevel.fixed' => [...$source, 'levels' => [['depth' => 1, 'amount' => '5'], ['depth' => 2, 'amount' => '2']]],
            'binary.pairing.fixed' => ['volume_type' => 'sales', 'pair_quantity' => '100', 'amount_per_pair' => '100'],
            'test.scripted' => [],
        };
    }

    private function period(PlanVersion $version, ?LedgerAccount $source = null, string $from = '2026-01-01', string $until = '2026-02-01', string $release = '2026-02-15', string $key = 'period:2026-01'): CommissionPeriod
    {
        return $this->app->make(CommissionPeriodManager::class)->create($this->program, $version, $source ?? $this->source, CarbonImmutable::parse($from), CarbonImmutable::parse($until), CarbonImmutable::parse($release), $key);
    }

    private function calculate(CommissionPeriod $period): CommissionPeriodResult
    {
        return $this->app->make(CommissionPeriodCalculator::class)->calculate($period);
    }

    private function finalizer(): CommissionPeriodFinalizer
    {
        return $this->app->make(CommissionPeriodFinalizer::class);
    }

    private function releaser(): CommissionPeriodReleaser
    {
        return $this->app->make(CommissionPeriodReleaser::class);
    }

    private function assertRefusedPosting(Commission $commission, string $reason): void
    {
        try {
            $this->poster()->post($commission);
            $this->fail('A period commission was posted early.');
        } catch (CommissionPeriodNotReleased $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        $this->assertSame(0, DB::table('mlm_ledger_transactions')->where('source_id', $commission->id)->count());
    }

    /**
     * @param  list<Commission>  $commissions
     * @return list<array{string, string}>
     */
    private function awarded(array $commissions): array
    {
        return array_map(fn (Commission $commission): array => [$this->code($commission), $commission->amount->value()], $commissions);
    }

    /**
     * By amount, largest first.
     *
     * @param  list<Commission>  $commissions
     * @return list<Commission>
     */
    private function ordered(array $commissions): array
    {
        usort($commissions, static fn (Commission $a, Commission $b): int => $b->amount_millionths <=> $a->amount_millionths);

        return $commissions;
    }

    /**
     * @return array{string, string}
     */
    private function adjusted(CommissionAdjustment $adjustment): array
    {
        return [$adjustment->amount->value(), $adjustment->outcome->value];
    }

    private function walletOf(string $code): string
    {
        return $this->balances()->forWallet(Wallet::query()->where('member_id', $this->members[$code]->id)->sole())->value();
    }

    private function code(Commission $commission): string
    {
        return Member::query()->findOrFail($commission->member_id)->member_code;
    }

    /**
     * @param  list<CalculationRun>  $models
     * @return list<string>
     */
    private function ids(array $models): array
    {
        return array_map(static fn (CalculationRun $model): string => (string) $model->getKey(), $models);
    }

    private function entry(string $key): VolumeEntry
    {
        return VolumeEntry::query()->where('idempotency_key', $key)->sole();
    }
}
