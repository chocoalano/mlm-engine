<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Commission\CommissionAdjustmentEngine;
use PandaBear\Mlm\Commission\CommissionAdjustmentOutcome;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Exceptions\InvalidCommissionCandidate;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsFixedCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `matrix.fixed` and `matrix.proportional` (ADR-027): every eligible
 * business entry pays the members above its member in the matrix, at the
 * configured physical matrix depths, as the matrix stood when the entry
 * took effect — no compression, no sponsorship, no generic placement —
 * each commission recording its source entry, so a later reversal claws it
 * back through the source-reversal engine unchanged.
 */
final class MatrixCommissionStrategyTest extends DatabaseTestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        // Matrix, from January: R > A #1 > B #2 > C #1. S sponsors C; G is
        // placed under C generically only.
        $this->plan = Plan::factory()->create();
        $this->members = $this->members($this->plan->program, 'R', 'A', 'B', 'C', 'S', 'G');
        $this->matrixNetworks()->configure($this->plan->program, 3);
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->matrix()->place($this->members['A'], $this->members['R'], 1);
        $this->matrix()->place($this->members['B'], $this->members['A'], 2);
        $this->matrix()->place($this->members['C'], $this->members['B'], 1);
        $this->placement()->place($this->members['G'], $this->members['C']);
        $this->genealogy()->assignSponsor($this->members['C'], $this->members['S']);
        $this->travelBack();
    }

    public function test_valid_components_validate_whatever_order_their_levels_are_listed_in(): void
    {
        $fixed = $this->fixedComponent('matrix.fixed', $this->matrixFixed(['levels' => [['depth' => 2, 'amount' => '5'], ['depth' => 1, 'amount' => '10']]]), $this->plan);
        $proportional = $this->fixedComponent('matrix.proportional', $this->matrixProportional(), $this->plan);

        $this->assertSame([PlanVersionStatus::Validated, PlanVersionStatus::Validated], [$fixed->planVersion->status, $proportional->planVersion->status]);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, array<string, RuleDefinition>, string}>
     */
    public static function invalidDefinitions(): array
    {
        $rule = ['eligible' => RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '1'))];

        return [
            'fixed: no levels' => ['matrix.fixed', ['levels' => []], [], '"levels" is a non-empty list'],
            'fixed: a depth as text' => ['matrix.fixed', ['levels' => [['depth' => '1', 'amount' => '10']]], [], 'from 1 to 100; string given'],
            'fixed: a depth over 100' => ['matrix.fixed', ['levels' => [['depth' => 101, 'amount' => '10']]], [], 'from 1 to 100; 101 given'],
            'fixed: a depth twice' => ['matrix.fixed', ['levels' => [['depth' => 2, 'amount' => '10'], ['depth' => 2, 'amount' => '5']]], [], 'names depth 2 more than once'],
            'fixed: a zero amount' => ['matrix.fixed', ['levels' => [['depth' => 1, 'amount' => '0']]], [], 'is a strictly positive award'],
            'fixed: an amount beyond one posting' => ['matrix.fixed', ['levels' => [['depth' => 1, 'amount' => '9223372036854.775808']]], [], 'more than one ledger posting holds'],
            'fixed: a width' => ['matrix.fixed', ['width' => 3], [], 'unknown: width'],
            'fixed: rules' => ['matrix.fixed', [], $rule, 'matrix.fixed rules: this strategy takes no rules'],
            'proportional: no rounding' => ['matrix.proportional', ['rounding' => 'nearest'], [], 'rounding'],
            'proportional: a float unit amount' => ['matrix.proportional', ['levels' => [['depth' => 1, 'unit_amount' => 0.1]]], [], 'a float cannot hold most decimals exactly'],
            'proportional: rules' => ['matrix.proportional', [], $rule, 'matrix.proportional rules: this strategy takes no rules'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, RuleDefinition>  $rules
     */
    #[DataProvider('invalidDefinitions')]
    public function test_invalid_definitions_block_validation(string $strategy, array $overrides, array $rules, string $reason): void
    {
        $this->systemAccounts()->openSystemAccount($this->plan->program, 'IDR', 'commission.payable');
        $draft = $this->draft($this->plan);
        $parameters = $strategy === 'matrix.fixed' ? $this->matrixFixed($overrides) : $this->matrixProportional($overrides);
        $this->addCommissionComponent($draft, $this->commissionParameters(['strategy' => $strategy, 'parameters' => $parameters]), rules: $rules);

        try {
            $this->lifecycle()->markValidated($draft);
            $this->fail('An invalid definition was validated.');
        } catch (InvalidPlanDefinition $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        $this->assertSame(PlanVersionStatus::Draft, $draft->refresh()->status);
    }

    public function test_each_configured_depth_pays_the_matrix_ancestor_at_that_depth(): void
    {
        $sale = $this->sale($this->members['C'], '150', '2026-01-10', 'order:A');
        $run = $this->monthly($this->fixed([1 => '10', 2 => '5', 3 => '2', 4 => '1']), '2026-01');

        // A line of three: depth 4 finds nobody. The sponsor earns nothing.
        $this->assertSame([
            ['A', '5', 2, $sale->id, '2026-01-10 00:00:00'],
            ['B', '10', 1, $sale->id, '2026-01-10 00:00:00'],
            ['R', '2', 3, $sale->id, '2026-01-10 00:00:00'],
        ], $this->sortedAwards($run));
    }

    public function test_a_gap_in_the_depths_is_kept_not_compressed(): void
    {
        $this->sale($this->members['C'], '150', '2026-01-10', 'order:A');

        $this->assertSame([['A', '5', 2]], $this->recipients($this->monthly($this->fixed([2 => '5']), '2026-01')));
    }

    public function test_a_matrix_root_or_a_generic_only_member_pays_nobody(): void
    {
        $this->sale($this->members['R'], '150', '2026-01-10', 'order:R');
        $this->sale($this->members['G'], '150', '2026-01-10', 'order:G');
        $this->sale($this->members['S'], '150', '2026-01-10', 'order:S');

        $this->assertSame([], $this->recipients($this->monthly($this->fixed([1 => '10', 2 => '5']), '2026-01')));
    }

    public function test_the_matrix_as_it_stood_at_the_entry_decides_never_the_matrix_now(): void
    {
        // D is placed generically under C in January, sells, and is adopted
        // into slot 3 in March: its January sale pays nobody, its April one
        // pays C, B and A.
        $this->members['D'] = Member::factory()->for($this->plan->program)->create(['member_code' => 'D']);
        $this->travelTo(CarbonImmutable::parse('2026-01-02 00:00:00'));
        $edge = $this->placement()->place($this->members['D'], $this->members['C']);
        $this->travelBack();
        $this->sale($this->members['D'], '150', '2026-01-10', 'order:jan');
        $this->travelTo(CarbonImmutable::parse('2026-03-01 00:00:00'));
        $this->matrix()->adopt($edge, 3);
        $this->travelBack();
        $this->sale($this->members['D'], '150', '2026-04-10', 'order:apr');
        $component = $this->fixed([1 => '10', 2 => '5', 3 => '2']);

        $this->assertSame([], $this->recipients($this->monthly($component, '2026-01')));
        $this->assertSame([['A', '2', 3], ['B', '5', 2], ['C', '10', 1]], $this->recipients($this->monthly($component, '2026-04')));
    }

    public function test_the_minimum_and_the_volume_and_source_types_decide_eligibility(): void
    {
        $this->sale($this->members['C'], '100', '2026-01-10', 'order:at-minimum');
        $this->sale($this->members['C'], '99.999999', '2026-01-11', 'order:below');
        $this->sale($this->members['C'], '500', '2026-01-12', 'points:1', type: 'points');
        $this->sale($this->members['C'], '500', '2026-01-13', 'bonus:1', sourceType: 'bonus');

        $run = $this->monthly($this->fixed([1 => '10'], ['minimum_quantity' => '100']), '2026-01');

        $this->assertSame(['order:at-minimum'], $this->sourceKeys($run));
    }

    public function test_a_reversal_before_the_cutoff_suppresses_the_entry_and_one_at_or_after_it_does_not(): void
    {
        $before = $this->sale($this->members['C'], '150', '2026-01-10', 'order:before');
        $at = $this->sale($this->members['C'], '150', '2026-01-11', 'order:at');
        $later = $this->sale($this->members['C'], '150', '2026-01-12', 'order:later');
        $this->reverse($before, 'refund:before', at: CarbonImmutable::parse('2026-01-31 23:59:59'));
        $this->reverse($at, 'refund:at', at: CarbonImmutable::parse('2026-02-01 00:00:00'));
        $this->reverse($later, 'refund:later', at: CarbonImmutable::parse('2026-03-15 00:00:00'));

        $this->assertSame(['order:at', 'order:later'], $this->sourceKeys($this->monthly($this->fixed([1 => '10']), '2026-01')));
    }

    public function test_every_commission_names_its_source_entry_and_explains_itself(): void
    {
        $sale = $this->sale($this->members['C'], '150', '2026-01-10', 'order:A');
        $commission = $this->monthly($this->fixed([2 => '5']), '2026-01')->commissions()->sole();

        $this->assertSame(["volume-entry:{$sale->id}:depth:2", 'volume-entry', $sale->id, '5'], [$commission->candidate_key, $commission->source_type, $commission->source_id, $commission->amount->value()]);
        $this->assertSame([
            'amount' => '5',
            'calculation' => ['amount' => '5'],
            'matrix' => ['depth' => 2, 'recipient_member_id' => $this->members['A']->id],
            'minimum_quantity' => '100',
            'recipient' => ['depth' => 2, 'member_id' => $this->members['A']->id],
            'source' => [
                'effective_at' => '2026-01-10 00:00:00',
                'member_id' => $this->members['C']->id,
                'quantity' => '150',
                'source_id' => 'ORDER:A',
                'source_type' => 'order',
                'volume_entry_id' => $sale->id,
                'volume_type' => 'sales',
            ],
            'strategy' => 'matrix.fixed',
        ], $commission->trace);
    }

    public function test_the_proportional_award_is_the_quantity_times_each_depths_amount_per_unit_rounded_on_its_own(): void
    {
        // 150.000005 × 0.1 = 15.0000005; × 0.05 = 7.50000025; × 0.000001 = 0.000150000005.
        $sale = $this->sale($this->members['C'], '150.000005', '2026-01-10', 'order:A');
        $component = $this->fixedComponent('matrix.proportional', $this->matrixProportional(['levels' => [
            ['depth' => 1, 'unit_amount' => '0.1'], ['depth' => 2, 'unit_amount' => '0.05'], ['depth' => 3, 'unit_amount' => '0.000001'],
        ]]), $this->plan);

        $run = $this->monthly($component, '2026-01');

        // Half even: 15.0000005 → 15, 7.50000025 → 7.5, 0.000150000005 → 0.00015.
        $this->assertSame([['A', '7.5', 2], ['B', '15', 1], ['R', '0.00015', 3]], $this->recipients($run));
        $commission = $run->commissions()->where('candidate_key', "volume-entry:{$sale->id}:depth:1")->sole();
        $this->assertSame(['0.1', '15.0000005', 'half_even', true, '15'], [
            $commission->trace['calculation']['unit_amount'], $commission->trace['calculation']['exact_amount'],
            $commission->trace['calculation']['rounding'], $commission->trace['calculation']['rounded'], $commission->trace['calculation']['amount'],
        ]);
        $this->assertSame(['matrix.proportional', ['depth' => 1, 'recipient_member_id' => $this->members['B']->id], 'volume-entry', $sale->id], [$commission->trace['strategy'], $commission->trace['matrix'], $commission->source_type, $commission->source_id]);
    }

    public function test_a_depth_whose_award_rounds_to_zero_earns_nothing_while_the_others_earn(): void
    {
        // 0.4 × 0.000002 = 0.0000008 → 0.000001; 0.4 × 0.000001 = 0.0000004 → 0.
        $this->sale($this->members['C'], '0.4', '2026-01-10', 'order:A');
        $component = $this->fixedComponent('matrix.proportional', $this->matrixProportional(['minimum_quantity' => '0', 'levels' => [
            ['depth' => 1, 'unit_amount' => '0.000002'], ['depth' => 2, 'unit_amount' => '0.000001'],
        ]]), $this->plan);

        $this->assertSame([['B', '0.000001', 1]], $this->recipients($this->monthly($component, '2026-01')));
    }

    public function test_a_large_quantity_is_exact_and_an_award_beyond_one_posting_fails_the_whole_run(): void
    {
        $this->sale($this->members['C'], '999999999999.999999', '2026-01-10', 'order:huge');
        $exact = $this->fixedComponent('matrix.proportional', $this->matrixProportional(['levels' => [['depth' => 1, 'unit_amount' => '0.000001']]]), $this->plan);

        $this->assertSame([['B', '1000000', 1]], $this->recipients($this->monthly($exact, '2026-01')));

        $huge = $this->fixedComponent('matrix.proportional', $this->matrixProportional(['levels' => [['depth' => 1, 'unit_amount' => '100']]]), Plan::factory()->for($this->plan->program)->create());

        try {
            $this->monthly($huge, '2026-01', 'run:huge');
            $this->fail('An award larger than one posting was accepted.');
        } catch (InvalidCommissionCandidate $exception) {
            $this->assertStringContainsString('one ledger posting, which holds at most 9223372036854.775807', $exception->getMessage());
        }

        $this->assertSame(0, CalculationRun::query()->where('idempotency_key', 'run:huge')->count());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function postedOrNot(): array
    {
        return ['approved' => [false], 'posted' => [true]];
    }

    #[DataProvider('postedOrNot')]
    public function test_a_later_reversal_claws_matrix_commissions_back_through_the_source_reversal_engine(bool $posted): void
    {
        $sale = $this->sale($this->members['C'], '150', '2026-01-10', 'order:A');
        $run = $this->monthly($this->fixed([1 => '10', 2 => '5']), '2026-01');

        foreach ($run->commissions()->get() as $commission) {
            $commission = $this->approved($commission);

            if ($posted) {
                $this->poster()->post($commission);
            }
        }

        $reversal = $this->reverse($sale, 'refund:A', at: CarbonImmutable::parse('2026-03-10'));
        $result = $this->app->make(CommissionAdjustmentEngine::class)->processVolumeReversal($reversal);

        $this->assertSame(2, $result->count($posted ? CommissionAdjustmentOutcome::Reversed : CommissionAdjustmentOutcome::Cancelled));
        $this->assertSame(['-10', '-5'], collect($result->adjustments)->map(static fn ($adjustment): string => $adjustment->amount->value())->sort()->values()->all());
        $this->assertSame([$posted ? CommissionStatus::Reversed : CommissionStatus::Cancelled], Commission::query()->pluck('status')->unique()->values()->all());
        $this->assertSame(['clawback', 'volume-entry-reversal'], [$result->adjustments[0]->type, $result->adjustments[0]->source_type]);

        if ($posted) {
            foreach (Wallet::query()->get() as $wallet) {
                $this->assertSame('0', $this->balances()->forWallet($wallet)->value());
            }
        }
    }

    public function test_the_matrix_is_read_once_per_chunk_of_entries_not_once_per_entry(): void
    {
        $component = $this->fixed([1 => '10', 2 => '5']);
        $this->sale($this->members['C'], '150', '2026-01-10', 'order:1');
        $few = $this->genealogyReads(fn (): CalculationRun => $this->monthly($component, '2026-01'));

        foreach (range(1, 12) as $i) {
            $this->sale($this->members[['A', 'B', 'C'][$i % 3]], '150', '2026-02-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), "order:feb-{$i}");
        }

        $many = $this->genealogyReads(fn (): CalculationRun => $this->monthly($component, '2026-02'));

        // January: C pays B and A. February: A pays R; B and C pay two each.
        $this->assertSame(1, $few);
        $this->assertSame($few, $many);
        $this->assertSame(2 + 4 + 8 + 8, Commission::query()->count());
    }

    /**
     * @param  array<int, string>  $amounts  by depth
     * @param  array<string, mixed>  $overrides
     */
    private function fixed(array $amounts, array $overrides = []): PlanComponent
    {
        $levels = [];

        foreach ($amounts as $depth => $amount) {
            $levels[] = ['depth' => $depth, 'amount' => $amount];
        }

        return $this->fixedComponent('matrix.fixed', $this->matrixFixed(['levels' => $levels, ...$overrides]), Plan::factory()->for($this->plan->program)->create());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function matrixFixed(array $overrides = []): array
    {
        return [
            'volume_type' => 'sales',
            'source_type' => 'order',
            'minimum_quantity' => '100',
            'levels' => [['depth' => 1, 'amount' => '10'], ['depth' => 2, 'amount' => '5']],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function matrixProportional(array $overrides = []): array
    {
        return [
            'volume_type' => 'sales',
            'source_type' => 'order',
            'minimum_quantity' => '1',
            'rounding' => 'half_even',
            'levels' => [['depth' => 1, 'unit_amount' => '0.1'], ['depth' => 2, 'unit_amount' => '0.05']],
            ...$overrides,
        ];
    }

    /**
     * @return list<array{string, string, int, string, string}>
     */
    private function sortedAwards(CalculationRun $run): array
    {
        $awards = $this->awards($run);
        sort($awards);

        return $awards;
    }

    /**
     * @return list<array{string, string, int}> recipient code, amount and depth, sorted
     */
    private function recipients(CalculationRun $run): array
    {
        return array_map(static fn (array $award): array => array_slice($award, 0, 3), $this->sortedAwards($run));
    }

    /**
     * @return list<string> the idempotency keys of the entries the run paid on
     */
    private function sourceKeys(CalculationRun $run): array
    {
        return DB::table('mlm_volume_entries')
            ->whereIn('id', $run->commissions()->pluck('source_id'))
            ->orderBy('idempotency_key')
            ->pluck('idempotency_key')
            ->all();
    }

    private function genealogyReads(callable $calculate): int
    {
        $reads = 0;
        DB::listen(static function (QueryExecuted $query) use (&$reads): void {
            if (str_contains($query->sql, 'mlm_genealogy_paths')) {
                $reads++;
            }
        });
        $calculate();
        DB::getEventDispatcher()?->forget(QueryExecuted::class);

        return $reads;
    }
}
