<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Calculation\CalculationContext;
use PandaBear\Mlm\Calculation\StatefulCalculationTransaction;
use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionCandidate;
use PandaBear\Mlm\Commission\CommissionStrategyDefinition;
use PandaBear\Mlm\Exceptions\InvalidCalculationRun;
use PandaBear\Mlm\Exceptions\InvalidCommissionCandidate;
use PandaBear\Mlm\Finance\CurrencyCode;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Fixtures\StatefulProbeStrategy;
use PDOException;
use RuntimeException;

/**
 * The calculation engine's generic stateful extension (ADR-023): a stateful
 * strategy's transition is applied once, with the run it belongs to, in
 * one serializable transaction retried from its first read; a stateless
 * strategy calculates exactly as before.
 */
final class StatefulCalculationTest extends DatabaseTestCase
{
    use BuildsCommissions;
    use BuildsLedgers;
    use BuildsPlanDefinitions;

    private StatefulProbeStrategy $probe;

    private PlanComponent $component;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand-in strategy state: a row per application, with its run.
        Schema::create('test_stateful_state', static function (Blueprint $table): void {
            $table->id();
            $table->string('calculation_run_id');
            $table->unsignedInteger('commissions');
        });

        $this->probe = $this->strategy(new StatefulProbeStrategy);
        $this->component = $this->commissionComponent(['strategy' => 'test.stateful', 'parameters' => []]);
        $this->member = Member::factory()->for($this->component->planVersion->plan->program)->create();
        $this->probe->script = fn (): array => [new CommissionCandidate('only', $this->member, '5', CarbonImmutable::parse('2026-06-30'))];
        $this->probe->apply = static function (Connection $db, CalculationRun $run): void {
            $db->table('test_stateful_state')->insert([
                'calculation_run_id' => $run->id,
                'commissions' => $db->table('mlm_commissions')->where('calculation_run_id', $run->id)->count(),
            ]);
        };
    }

    public function test_a_transition_is_applied_once_after_its_run_and_commissions_are_stored(): void
    {
        $run = $this->calculate($this->component);

        $this->assertSame([[$run->id, 1]], $this->state());
        $this->assertSame([1, 1], [$this->probe->calculations, $this->probe->applications]);
        $this->assertSame(['only'], $run->commissions()->pluck('candidate_key')->all());
    }

    public function test_the_context_names_the_stored_component(): void
    {
        $this->calculate($this->component);

        $this->assertSame($this->component->id, $this->probe->context?->planComponentId);
    }

    public function test_a_replay_calculates_nothing_and_applies_nothing(): void
    {
        $first = $this->calculate($this->component);
        $again = $this->calculate($this->component);

        $this->assertTrue($first->is($again));
        $this->assertSame([1, 1], [$this->probe->calculations, $this->probe->applications]);
        $this->assertSame([[$first->id, 1]], $this->state());
    }

    public function test_a_failing_transition_keeps_no_run_no_commission_and_no_state(): void
    {
        $this->probe->apply = static function (Connection $db, CalculationRun $run): void {
            $db->table('test_stateful_state')->insert(['calculation_run_id' => $run->id, 'commissions' => 0]);

            throw new RuntimeException('The state could not be stored.');
        };

        try {
            $this->calculate($this->component);
            $this->fail('A failing transition was committed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The state could not be stored.', $exception->getMessage());
        }

        $this->assertSame([0, 0, []], [CalculationRun::query()->count(), DB::table('mlm_commissions')->count(), $this->state()]);

        // Not a concurrency failure: tried once.
        $this->assertSame(1, $this->probe->calculations);
    }

    public function test_every_candidate_is_checked_before_the_transition_runs(): void
    {
        $outsider = Member::factory()->create();
        $this->probe->script = static fn (): array => [new CommissionCandidate('elsewhere', $outsider, '5', CarbonImmutable::parse('2026-06-30'))];

        try {
            $this->calculate($this->component);
            $this->fail('A candidate of another program was accepted.');
        } catch (InvalidCommissionCandidate) {
        }

        $this->assertSame([0, 0, []], [$this->probe->applications, CalculationRun::query()->count(), $this->state()]);
    }

    public function test_a_concurrency_failure_is_retried_from_the_first_read_and_keeps_one_result(): void
    {
        $this->probe->apply = static function (Connection $db, CalculationRun $run, int $attempt): void {
            $db->table('test_stateful_state')->insert(['calculation_run_id' => $run->id, 'commissions' => $attempt]);

            if ($attempt === 1) {
                throw new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            }
        };

        $run = $this->calculate($this->component);

        // Calculated again from scratch; the first attempt left nothing.
        $this->assertSame([2, 2], [$this->probe->calculations, $this->probe->applications]);
        $this->assertSame([[$run->id, 2]], $this->state());
        $this->assertSame(1, CalculationRun::query()->count());
        $this->assertSame(1, DB::table('mlm_commissions')->count());
    }

    public function test_retries_are_bounded(): void
    {
        $this->probe->apply = static function (): void {
            throw new PDOException('SQLSTATE[40001]: Serialization failure: could not serialize access due to read/write dependencies among transactions', 40001);
        };

        try {
            $this->calculate($this->component);
            $this->fail('A calculation that could never commit did not stop.');
        } catch (PDOException) {
        }

        $this->assertSame(StatefulCalculationTransaction::ATTEMPTS, $this->probe->calculations);
        $this->assertSame([0, []], [CalculationRun::query()->count(), $this->state()]);
    }

    public function test_it_refuses_to_start_inside_a_callers_transaction(): void
    {
        $this->expectException(InvalidCalculationRun::class);
        $this->expectExceptionMessage('is already inside a transaction');

        DB::transaction(fn (): CalculationRun => $this->calculate($this->component));
    }

    public function test_a_preview_through_calculate_moves_no_state(): void
    {
        $candidates = [...$this->probe->calculate(new CommissionCalculationContext(
            $this->component->planVersion->plan->program,
            new CommissionStrategyDefinition($this->component->plan_version_id, 1, $this->component->key, 'Sales commission', 'test.stateful', CurrencyCode::of('IDR'), 'commission.payable', [], []),
            CarbonImmutable::parse('2026-06-01'),
            CarbonImmutable::parse('2026-07-01'),
            (string) DB::connection()->getName(),
        ))];

        $this->assertCount(1, $candidates);
        $this->assertSame([0, []], [$this->probe->applications, $this->state()]);
    }

    public function test_a_stateless_strategy_is_calculated_as_before(): void
    {
        $fixed = $this->commissionComponent(plan: $this->component->planVersion->plan);
        $run = $this->engine()->calculate($fixed, new CalculationContext(CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-07-01'), 'fixed:2026-06'));

        $this->assertSame(1, $run->commissions()->count());
        $this->assertSame([0, 0], [$this->probe->calculations, $this->probe->applications]);
        $this->assertSame([], $this->state());
    }

    public function test_a_stateful_run_is_serializable_while_a_stateless_run_keeps_its_snapshot(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->markTestSkipped('SQLite has no isolation levels to tell apart: it lets one writer commit at a time.');
        }

        // The level of the transaction the calculation runs in, read from
        // inside it — MySQL reports it per transaction only here.
        $level = static fn (): string => strtolower((string) ($driver === 'pgsql'
            ? DB::selectOne('SHOW transaction_isolation')->transaction_isolation
            : DB::selectOne('SELECT ISOLATION_LEVEL AS level FROM performance_schema.events_transactions_current WHERE THREAD_ID = PS_CURRENT_THREAD_ID()')->level));
        $levels = [];

        $this->probe->script = function () use ($level, &$levels): array {
            $levels['stateful'] = $level();

            return [];
        };
        $this->scriptedStrategy()->script = static function () use ($level, &$levels): array {
            $levels['stateless'] = $level();

            return [];
        };

        $this->calculate($this->component);
        $this->calculate($this->commissionComponent(['strategy' => 'test.scripted', 'parameters' => []], $this->component->planVersion->plan), key: 'scripted');

        $this->assertSame(['stateful' => 'serializable', 'stateless' => 'repeatable read'], $levels);
    }

    /**
     * @return list<array{string, int}>
     */
    private function state(): array
    {
        return DB::table('test_stateful_state')->orderBy('id')->get()
            ->map(static fn (object $row): array => [(string) $row->calculation_run_id, (int) $row->commissions])
            ->all();
    }
}
