<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Calculation\CalculationContext;
use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionCandidate;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Commission\CommissionStrategyRegistry;
use PandaBear\Mlm\Exceptions\ConflictingCalculationReplay;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Exceptions\InvalidCalculationRun;
use PandaBear\Mlm\Exceptions\InvalidCommissionCandidate;
use PandaBear\Mlm\Exceptions\InvalidFinancialAmount;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Qualification\QualificationEngine;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PandaBear\Mlm\Tests\Fixtures\QualifiedCommissionStrategy;
use PandaBear\Mlm\Tests\Fixtures\SnapshotProbeStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * One chosen commission component, calculated over a closed range: stored
 * whole or not at all, replayed without recalculating, and never moving
 * money.
 */
final class CalculationEngineTest extends DatabaseTestCase
{
    use BuildsCommissions;
    use BuildsLedgers;
    use BuildsPlanDefinitions;
    use RecordsVolume;

    public function test_a_run_stores_every_candidate_as_a_calculated_commission(): void
    {
        $plan = Plan::factory()->create();
        $bob = Member::factory()->for($plan->program)->create(['member_code' => 'BOB']);
        $alice = Member::factory()->for($plan->program)->create(['member_code' => 'ALICE']);
        Member::factory()->create(['member_code' => 'OUTSIDER']);
        $component = $this->commissionComponent(plan: $plan);

        $run = $this->calculate($component);
        $source = $this->systemAccounts()->openSystemAccount($plan->program, 'IDR', 'commission.payable');

        $this->assertSame(
            [$plan->program_id, $component->plan_version_id, $component->id, 'test.fixed', 'IDR', $source->id, '2026-06-01 00:00:00', '2026-07-01 00:00:00', 'run:2026-06'],
            [$run->program_id, $run->plan_version_id, $run->plan_component_id, $run->strategy, $run->currency, $run->source_ledger_account_id, $run->from_at->format('Y-m-d H:i:s'), $run->until_at->format('Y-m-d H:i:s'), $run->idempotency_key],
        );

        $commissions = $run->commissions()->get();
        $this->assertSame(['member:ALICE', 'member:BOB'], $commissions->pluck('candidate_key')->all());
        $this->assertSame([$alice->id, $bob->id], $commissions->pluck('member_id')->all());

        $first = $commissions->first();
        $this->assertInstanceOf(Commission::class, $first);
        $this->assertSame(
            [$plan->program_id, 'IDR', '10.5', '2026-06-30 23:59:59', CommissionStatus::Calculated, null, null, null, null],
            [$first->program_id, $first->currency, $first->amount->value(), $first->earned_at->format('Y-m-d H:i:s'), $first->status, $first->pending_at, $first->approved_at, $first->posted_at, $first->ledger_transaction_id],
        );
        $this->assertSame([
            'amount' => '10.5',
            'member_code' => 'ALICE',
            'range' => ['from' => '2026-06-01 00:00:00', 'until' => '2026-07-01 00:00:00'],
            'rules' => [],
            'strategy' => 'test.fixed',
        ], $first->trace);
        $this->assertTrue($first->run->is($run));
        $this->assertTrue($first->member->is($alice));
        $this->assertTrue($first->program->is($plan->program));
        $this->assertTrue($run->component->is($component));
        $this->assertTrue($run->sourceAccount->is($source));
        $this->assertSame([$run->id], $plan->program->calculationRuns()->pluck('id')->all());
        $this->assertSame(2, $plan->program->commissions()->count());
        $this->assertSame([$run->id], $component->calculationRuns()->pluck('id')->all());
    }

    public function test_calculating_moves_no_money_and_opens_no_wallet(): void
    {
        $plan = Plan::factory()->create();
        Member::factory()->for($plan->program)->count(3)->create();
        $component = $this->commissionComponent(plan: $plan);
        $before = $this->ledgerRows();

        $this->calculate($component);

        $this->assertSame($before, $this->ledgerRows());
        $this->assertSame([CommissionStatus::Calculated], Commission::query()->get()->pluck('status')->unique()->values()->all());
    }

    /**
     * @return array<string, array{PlanVersionStatus}>
     */
    public static function calculableStatuses(): array
    {
        return [
            'validated' => [PlanVersionStatus::Validated],
            'published' => [PlanVersionStatus::Published],
            'active' => [PlanVersionStatus::Active],
            'superseded' => [PlanVersionStatus::Superseded],
            'archived' => [PlanVersionStatus::Archived],
        ];
    }

    #[DataProvider('calculableStatuses')]
    public function test_a_component_of_any_validated_version_can_be_calculated(PlanVersionStatus $status): void
    {
        $component = $this->commissionComponent();
        Member::factory()->for($component->planVersion->plan->program)->create();
        $version = $component->planVersion;
        $lifecycle = $this->lifecycle();

        if ($status !== PlanVersionStatus::Validated) {
            $version = $lifecycle->publish($version);
        }

        if (in_array($status, [PlanVersionStatus::Active, PlanVersionStatus::Superseded, PlanVersionStatus::Archived], true)) {
            $version = $lifecycle->activate($version);
        }

        if (in_array($status, [PlanVersionStatus::Superseded, PlanVersionStatus::Archived], true)) {
            $lifecycle->activate($lifecycle->publish($lifecycle->markValidated($this->cloner()->cloneToNewDraft($version))));
        }

        if ($status === PlanVersionStatus::Archived) {
            $lifecycle->archive($version->refresh());
        }

        $this->assertSame($status, $version->refresh()->status);

        $run = $this->calculate($component);

        $this->assertSame($version->id, $run->plan_version_id);
        $this->assertSame(1, $run->commissions()->count());
    }

    public function test_a_draft_component_is_refused(): void
    {
        $this->fixedStrategy();
        $plan = Plan::factory()->create();
        $this->systemAccounts()->openSystemAccount($plan->program, 'IDR', 'commission.payable');
        $component = $this->addCommissionComponent($this->draft($plan), $this->commissionParameters());

        $this->assertRefused(fn () => $this->calculate($component), 'its plan version is a draft');
    }

    public function test_a_component_of_another_driver_is_refused(): void
    {
        $version = $this->draft();
        $ladder = $this->addLadder($version, ['bronze' => [10, RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '1'))]]);
        $this->lifecycle()->markValidated($version);

        $this->assertRefused(fn () => $this->calculate($ladder), 'its driver is "rank.ladder", not "commission.strategy"');
    }

    public function test_the_stored_component_decides_not_the_instance(): void
    {
        $component = $this->commissionComponent();
        Member::factory()->for($component->planVersion->plan->program)->create();
        $draft = $this->draft();

        $component->forceFill(['driver' => 'rank.ladder', 'plan_version_id' => $draft->id]);
        $run = $this->calculate($component);

        $this->assertNotSame($draft->id, $run->plan_version_id);
        $this->assertSame(1, $run->commissions()->count());
    }

    public function test_only_the_chosen_component_is_calculated(): void
    {
        $strategy = $this->fixedStrategy();
        $plan = Plan::factory()->create();
        Member::factory()->for($plan->program)->create();
        $this->systemAccounts()->openSystemAccount($plan->program, 'IDR', 'commission.payable');
        $draft = $this->draft($plan);
        $first = $this->addCommissionComponent($draft, $this->commissionParameters(), 'first-commission');
        $this->addCommissionComponent($draft, $this->commissionParameters(['parameters' => ['amount' => '99']]), 'second-commission');
        $this->lifecycle()->markValidated($draft);

        $run = $this->calculate($first);

        $this->assertSame($first->id, $run->plan_component_id);
        $this->assertSame(['10.5'], Commission::query()->get()->map(static fn (Commission $commission): string => $commission->amount->value())->all());
        $this->assertSame(1, $strategy->calculations);
    }

    /**
     * @return array<string, array{string|null, string|null, string}>
     */
    public static function unclosedRanges(): array
    {
        return [
            'no start' => [null, '2026-07-01', 'both bounds are required'],
            'no end' => ['2026-06-01', null, 'both bounds are required'],
            'empty' => ['2026-06-01', '2026-06-01', 'must start before it ends'],
            'inverted' => ['2026-07-01', '2026-06-01', 'must start before it ends'],
        ];
    }

    #[DataProvider('unclosedRanges')]
    public function test_a_calculation_covers_a_closed_range(?string $from, ?string $until, string $reason): void
    {
        $this->expectException(InvalidCalculationRun::class);
        $this->expectExceptionMessage($reason);

        new CalculationContext($from === null ? null : CarbonImmutable::parse($from), $until === null ? null : CarbonImmutable::parse($until), 'run:1');
    }

    public function test_a_calculation_refuses_to_run_inside_a_callers_transaction(): void
    {
        $strategy = $this->fixedStrategy();
        $component = $this->commissionComponent();

        try {
            DB::transaction(fn () => $this->calculate($component));
            $this->fail('A calculation ran inside a caller\'s transaction.');
        } catch (InvalidCalculationRun $exception) {
            $this->assertStringContainsString('is already inside a transaction', $exception->getMessage());
        }

        $this->assertSame([0, 0, 0], [CalculationRun::query()->count(), Commission::query()->count(), $strategy->calculations]);
    }

    public function test_an_identical_replay_returns_the_stored_run_without_calculating_again(): void
    {
        $strategy = $this->fixedStrategy();
        $component = $this->commissionComponent();
        $program = $component->planVersion->plan->program;
        Member::factory()->for($program)->create(['member_code' => 'ALICE']);

        $run = $this->calculate($component);

        // The data changes; the stored result does not.
        Member::factory()->for($program)->create(['member_code' => 'BOB']);
        $again = $this->calculate($component);
        $sameInstants = $this->engine()->calculate($component, new CalculationContext(
            CarbonImmutable::parse('2026-06-01 07:00:00', 'Asia/Jakarta'),
            CarbonImmutable::parse('2026-07-01 07:00:00', 'Asia/Jakarta'),
            'run:2026-06',
        ));

        $this->assertSame([$run->id, $run->id], [$again->id, $sameInstants->id]);
        $this->assertSame(1, $strategy->calculations);
        $this->assertSame(['member:ALICE'], Commission::query()->pluck('candidate_key')->all());

        // A new key calculates anew.
        $this->assertSame(['member:ALICE', 'member:BOB'], $this->calculate($component, key: 'run:2026-06-b')->commissions()->pluck('candidate_key')->all());
        $this->assertSame(2, $strategy->calculations);
    }

    /**
     * @return array<string, array{Closure(self, PlanComponent): CalculationRun, string}>
     */
    public static function conflictingReplays(): array
    {
        return [
            'another component' => [
                static fn (self $test, PlanComponent $component): CalculationRun => $test->calculate($test->commissionComponent(plan: $component->planVersion->plan)),
                'which differs in plan_component, plan_version',
            ],
            'another start' => [
                static fn (self $test, PlanComponent $component): CalculationRun => $test->calculate($component, from: '2026-06-02 00:00:00'),
                'which differs in from',
            ],
            'another end' => [
                static fn (self $test, PlanComponent $component): CalculationRun => $test->calculate($component, until: '2026-06-30 00:00:00'),
                'which differs in until',
            ],
        ];
    }

    /**
     * @param  Closure(self, PlanComponent): CalculationRun  $replay
     */
    #[DataProvider('conflictingReplays')]
    public function test_a_replay_that_differs_is_refused(Closure $replay, string $reason): void
    {
        $strategy = $this->fixedStrategy();
        $component = $this->commissionComponent();
        Member::factory()->for($component->planVersion->plan->program)->create();
        $run = $this->calculate($component);

        try {
            $replay($this, $component);
            $this->fail('A conflicting replay was accepted.');
        } catch (ConflictingCalculationReplay $exception) {
            $this->assertStringContainsString("already recorded calculation run [{$run->id}], {$reason}", $exception->getMessage());
        }

        $this->assertSame([1, 1, 1], [CalculationRun::query()->count(), Commission::query()->count(), $strategy->calculations]);
    }

    /**
     * @return array<string, array{Closure(Member, Member): iterable<mixed>, class-string, string}>
     */
    public static function failedCalculations(): array
    {
        $earned = CarbonImmutable::parse('2026-06-30 12:00:00');

        return [
            'the strategy throws' => [
                static function (Member $member): iterable {
                    yield new CommissionCandidate('a', $member, '1', CarbonImmutable::parse('2026-06-30'));

                    throw new RuntimeException('The source system is unavailable.');
                },
                RuntimeException::class, 'The source system is unavailable.',
            ],
            'not a candidate' => [
                static fn (Member $member): iterable => [new CommissionCandidate('a', $member, '1', $earned), ['key' => 'b', 'amount' => '1']],
                InvalidCommissionCandidate::class, 'yields CommissionCandidate objects only; array given',
            ],
            'a key twice' => [
                static fn (Member $member): iterable => [new CommissionCandidate('same', $member, '1', $earned), new CommissionCandidate('same', $member, '2', $earned)],
                InvalidCommissionCandidate::class, 'Commission candidate key "same" was produced more than once',
            ],
            'a member of another program' => [
                static fn (Member $member, Member $outsider): iterable => [new CommissionCandidate('a', $member, '1', $earned), new CommissionCandidate('z', $outsider, '1', $earned)],
                InvalidCommissionCandidate::class, "not of the run's program",
            ],
            'a member that does not exist' => [
                static fn (Member $member): iterable => [new CommissionCandidate('a', (new Member)->forceFill(['id' => (new Member)->newUniqueId()]), '1', $earned)],
                InvalidCommissionCandidate::class, 'which does not exist',
            ],
            'a zero amount' => [
                static fn (Member $member): iterable => [new CommissionCandidate('a', $member, '1', $earned), new CommissionCandidate('b', $member, '0', $earned)],
                InvalidCommissionCandidate::class, 'a commission is strictly positive',
            ],
            'a negative amount' => [
                static fn (Member $member): iterable => [new CommissionCandidate('a', $member, '-5', $earned)],
                InvalidCommissionCandidate::class, 'a commission is strictly positive',
            ],
            'a float amount' => [
                static fn (Member $member): iterable => [new CommissionCandidate('a', $member, FinancialAmount::of(0.5), $earned)],
                InvalidFinancialAmount::class, 'a float cannot hold most decimals exactly',
            ],
            'an amount too large for one posting' => [
                static fn (Member $member): iterable => [new CommissionCandidate('a', $member, '9223372036854.775808', $earned)],
                InvalidCommissionCandidate::class, 'one ledger posting, which holds at most 9223372036854.775807',
            ],
            'a float in the trace' => [
                static fn (Member $member): iterable => [new CommissionCandidate('a', $member, '1', $earned, ['rate' => 0.1])],
                InvalidCommissionCandidate::class, 'trace.rate is a float',
            ],
            'an object in the trace' => [
                static fn (Member $member): iterable => [new CommissionCandidate('a', $member, '1', $earned, ['member' => $member])],
                InvalidCommissionCandidate::class, 'is PandaBear\Mlm\Models\Member, which is not JSON data',
            ],
            'a closure in the trace' => [
                static fn (Member $member): iterable => [new CommissionCandidate('a', $member, '1', $earned, ['next' => static fn (): int => 1])],
                InvalidCommissionCandidate::class, 'is Closure, which is not JSON data',
            ],
            'a trace too deep' => [
                static fn (Member $member): iterable => [new CommissionCandidate('a', $member, '1', $earned, array_reduce(range(1, 20), static fn (array $carry): array => ['next' => $carry], []))],
                InvalidCommissionCandidate::class, 'nests deeper than 16 levels',
            ],
            'a trace too large' => [
                static fn (Member $member): iterable => [new CommissionCandidate('a', $member, '1', $earned, ['notes' => str_repeat('x', 70_000)])],
                InvalidCommissionCandidate::class, 'larger than 65536 bytes',
            ],
            'a padded key' => [
                static fn (Member $member): iterable => [new CommissionCandidate(' a', $member, '1', $earned)],
                InvalidCommissionCandidate::class, 'candidate key is 1–191 characters',
            ],
            'a valid candidate, then a late invalid one' => [
                static function (Member $member) use ($earned): iterable {
                    foreach (range(1, 50) as $i) {
                        yield new CommissionCandidate("ok:{$i}", $member, '1', $earned);
                    }

                    yield new CommissionCandidate('late', $member, '-1', $earned);
                },
                InvalidCommissionCandidate::class, 'Commission candidate "late" has amount -1',
            ],
        ];
    }

    /**
     * @param  Closure(Member, Member): iterable<mixed>  $script
     * @param  class-string<\Throwable>  $exception
     */
    #[DataProvider('failedCalculations')]
    public function test_a_calculation_that_fails_stores_nothing(Closure $script, string $exception, string $reason): void
    {
        $strategy = $this->scriptedStrategy();
        $component = $this->commissionComponent(['strategy' => 'test.scripted', 'parameters' => []]);
        $member = Member::factory()->for($component->planVersion->plan->program)->create();
        $outsider = Member::factory()->create();
        $strategy->script = static fn (CommissionCalculationContext $context): iterable => $script($member, $outsider);

        try {
            $this->calculate($component);
            $this->fail('A failed calculation stored a run.');
        } catch (\Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown);
            $this->assertStringContainsString($reason, $thrown->getMessage());
        }

        $this->assertSame([0, 0], [CalculationRun::query()->count(), Commission::query()->count()]);
    }

    public function test_a_write_failing_after_the_run_is_stored_leaves_no_run_behind(): void
    {
        $component = $this->commissionComponent();
        Member::factory()->for($component->planVersion->plan->program)->count(3)->create();
        $this->failInsertsInto('mlm_commissions');

        try {
            $this->calculate($component);
            $this->fail('The run was stored although its commissions were not.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The disk is full.', $exception->getMessage());
        }

        $this->assertSame([0, 0], [CalculationRun::query()->count(), Commission::query()->count()]);
    }

    /**
     * @return array<string, array{Closure(self, Plan): void, string}>
     */
    public static function unusableSourceAccounts(): array
    {
        return [
            'it does not exist' => [static function (): void {}, 'does not exist in program'],
            'it belongs to another program' => [static fn (self $test): mixed => $test->systemAccounts()->openSystemAccount(Program::factory()->create(), 'IDR', 'commission.payable'), 'does not exist in program'],
            'it holds another currency' => [static fn (self $test, Plan $plan): mixed => $test->systemAccounts()->openSystemAccount($plan->program, 'USD', 'commission.payable'), 'does not exist in program'],
            'it is a wallet account' => [static function (self $test, Plan $plan): void {
                $wallet = $test->wallets()->open(Member::factory()->for($plan->program)->create(), 'IDR');
                DB::table('mlm_ledger_accounts')->where('wallet_id', $wallet->id)->update(['key' => 'commission.payable']);
            }, 'is the account of wallet'],
        ];
    }

    /**
     * @param  Closure(self, Plan): void  $arrange
     */
    #[DataProvider('unusableSourceAccounts')]
    public function test_a_source_account_that_cannot_pay_stops_the_run_before_it_calculates(Closure $arrange, string $reason): void
    {
        $strategy = $this->fixedStrategy();
        $plan = Plan::factory()->create();
        Member::factory()->for($plan->program)->create();
        $draft = $this->draft($plan);
        $component = $this->addCommissionComponent($draft, $this->commissionParameters());
        $this->lifecycle()->markValidated($draft);
        $arrange($this, $plan);
        $accounts = DB::table('mlm_ledger_accounts')->count();

        $this->assertRefused(fn () => $this->calculate($component), "its source account \"commission.payable\" (IDR) {$reason}");

        $this->assertSame(0, $strategy->calculations);
        $this->assertSame($accounts, DB::table('mlm_ledger_accounts')->count());
    }

    public function test_a_strategy_no_longer_registered_stops_the_run(): void
    {
        $component = $this->commissionComponent();
        $this->app->forgetInstance(CommissionStrategyRegistry::class);

        $this->assertRefused(fn () => $this->calculate($component), 'no commission strategy is registered under "test.fixed"');
    }

    public function test_a_stored_definition_the_strategy_refuses_now_stops_the_run(): void
    {
        $strategy = $this->fixedStrategy();
        $component = $this->commissionComponent();
        // Only a raw write changes a validated definition.
        DB::table('mlm_plan_components')->where('id', $component->id)->update(['parameters' => json_encode($this->commissionParameters(['parameters' => ['amount' => 'lots']]))]);

        $this->assertRefused(fn () => $this->calculate($component), 'its stored definition is not valid');
        $this->assertSame(0, $strategy->calculations);
    }

    public function test_the_trace_is_stored_and_read_in_canonical_order(): void
    {
        $strategy = $this->scriptedStrategy();
        $component = $this->commissionComponent(['strategy' => 'test.scripted', 'parameters' => []]);
        $member = Member::factory()->for($component->planVersion->plan->program)->create();
        $strategy->script = static fn (): iterable => [new CommissionCandidate('a', $member, '1', CarbonImmutable::parse('2026-06-30'), [
            'zeta' => ['b' => 2, 'a' => 1],
            'alpha' => ['list', 'keeps', 'order'],
            'mid' => ['nested' => ['y' => true, 'x' => null]],
            'text' => 'Ünïcode / slashes',
        ])];

        $trace = $this->calculate($component)->commissions()->sole()->trace;

        $expected = [
            'alpha' => ['list', 'keeps', 'order'],
            'mid' => ['nested' => ['x' => null, 'y' => true]],
            'text' => 'Ünïcode / slashes',
            'zeta' => ['a' => 1, 'b' => 2],
        ];
        $this->assertSame($expected, $trace);
        $this->assertSame(json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function test_a_strategy_composes_the_packages_read_services(): void
    {
        $this->strategy(new QualifiedCommissionStrategy($this->app->make(QualificationEngine::class)));
        $this->strategy(new SnapshotProbeStrategy($this->app->make(MetricEngine::class), static function (): void {}));
        $plan = Plan::factory()->create();
        $alice = Member::factory()->for($plan->program)->create(['member_code' => 'ALICE']);
        $bob = Member::factory()->for($plan->program)->create(['member_code' => 'BOB']);
        $this->record($alice, '150', 'alice-june', at: CarbonImmutable::parse('2026-06-10'));
        $this->record($bob, '50', 'bob-june', at: CarbonImmutable::parse('2026-06-10'));
        $this->record($bob, '500', 'bob-july', at: CarbonImmutable::parse('2026-07-10'));

        $qualified = $this->commissionComponent(['strategy' => 'test.qualified', 'parameters' => ['amount' => '7']], $plan, [
            'eligible' => RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100')),
        ]);
        $commission = $this->calculate($qualified)->commissions()->sole();

        $this->assertSame(['qualified:ALICE', '7'], [$commission->candidate_key, $commission->amount->value()]);
        $this->assertSame('150', $commission->trace['qualification']['children'][0]['value']);

        $probe = $this->commissionComponent(['strategy' => 'test.snapshot', 'parameters' => []], $plan);
        $traces = $this->calculate($probe, key: 'probe:2026-06')->commissions()->get()->pluck('trace')->all();

        $this->assertCount(2, $traces);
        $this->assertSame(['members' => 2, 'volume' => ['ALICE' => '150', 'BOB' => '50']], $traces[0]['first']);
        $this->assertSame($traces[0]['first'], $traces[0]['second']);
    }

    /**
     * @return array<string, array{Closure(CalculationRun, Commission): mixed}>
     */
    public static function modelWrites(): array
    {
        return [
            'changing a run' => [static fn (CalculationRun $run): mixed => $run->forceFill(['until_at' => '2026-08-01 00:00:00'])->save()],
            'deleting a run' => [static fn (CalculationRun $run): mixed => $run->delete()],
            'creating a run' => [static fn (CalculationRun $run): mixed => (new CalculationRun)->forceFill($run->only(['program_id', 'plan_version_id', 'plan_component_id', 'strategy', 'currency', 'source_ledger_account_id', 'from_at', 'until_at']) + ['idempotency_key' => 'other'])->save()],
            'changing a commission\'s amount' => [static fn (CalculationRun $run, Commission $commission): mixed => $commission->forceFill(['amount_millionths' => 1])->save()],
            'approving a commission directly' => [static fn (CalculationRun $run, Commission $commission): mixed => $commission->forceFill(['status' => CommissionStatus::Approved])->save()],
            'deleting a commission' => [static fn (CalculationRun $run, Commission $commission): mixed => $commission->delete()],
        ];
    }

    /**
     * @param  Closure(CalculationRun, Commission): mixed  $write
     */
    #[DataProvider('modelWrites')]
    public function test_runs_and_commissions_are_read_only_through_eloquent(Closure $write): void
    {
        $component = $this->commissionComponent();
        Member::factory()->for($component->planVersion->plan->program)->create();
        $run = $this->calculate($component);
        $before = [DB::table('mlm_calculation_runs')->get()->all(), DB::table('mlm_commissions')->get()->all()];

        try {
            $write($run, $run->commissions()->sole());
            $this->fail('A calculation row was written through its model.');
        } catch (ImmutableCalculationRecord $exception) {
            $this->assertStringContainsString('read-only through Eloquent', $exception->getMessage());
        }

        $this->assertEquals($before, [DB::table('mlm_calculation_runs')->get()->all(), DB::table('mlm_commissions')->get()->all()]);
    }

    /**
     * @param  Closure(): mixed  $calculation
     */
    private function assertRefused(Closure $calculation, string $reason): void
    {
        try {
            $calculation();
            $this->fail('The calculation ran.');
        } catch (InvalidCalculationRun $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        $this->assertSame([0, 0], [CalculationRun::query()->count(), Commission::query()->count()]);
    }
}
