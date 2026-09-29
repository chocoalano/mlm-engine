<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\InvalidCalculationRun;
use PandaBear\Mlm\Exceptions\InvalidCommissionCandidate;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
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
 * `direct-sponsor.proportional`: the historical direct sponsor earns the
 * entry's quantity times an amount per unit, rounded by the plan's explicit
 * mode, per entry — eligibility and attribution exactly as the fixed award.
 */
final class DirectSponsorProportionalStrategyTest extends DatabaseTestCase
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

        $this->plan = Plan::factory()->create();
        $this->members = $this->members($this->plan->program, 'ALICE', 'BOB', 'CAROL');
    }

    public function test_a_valid_component_validates_and_its_unit_amount_is_not_a_posting(): void
    {
        $component = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters(), $this->plan);
        // An amount per unit larger than one posting holds is still a rate.
        $large = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters(['unit_amount' => '100000000000000']), $this->plan);

        $this->assertSame(PlanVersionStatus::Validated, $component->planVersion->status);
        $this->assertSame(PlanVersionStatus::Validated, $large->planVersion->status);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidParameters(): array
    {
        return [
            'no unit amount' => [['unit_amount' => null], 'missing: unit_amount'],
            'no rounding' => [['rounding' => null], 'missing: rounding'],
            'a percentage' => [['percentage' => '5'], 'unknown: percentage'],
            'a fixed amount' => [['amount' => '10'], 'unknown: amount'],
            'an uppercase volume type' => [['volume_type' => 'Sales'], '"volume_type": The volume type must be'],
            'an uppercase source type' => [['source_type' => 'Order'], '"source_type": The volume source type must be'],
            'a negative minimum' => [['minimum_quantity' => '-1'], '"minimum_quantity" is zero or more'],
            'a float minimum' => [['minimum_quantity' => 1.5], 'float 1.5 is not a volume quantity'],
            'a zero unit amount' => [['unit_amount' => '0'], '"unit_amount" is strictly positive'],
            'a negative unit amount' => [['unit_amount' => '-1.25'], '"unit_amount" is strictly positive'],
            'a float unit amount' => [['unit_amount' => 1.25], 'a float cannot hold most decimals exactly'],
            'seven decimal places' => [['unit_amount' => '0.0000001'], 'more than 6 decimal places'],
            'a rounding in capitals' => [['rounding' => 'HALF_EVEN'], '"rounding" is one of toward_zero, away_from_zero, half_up, half_even, spelled exactly; "HALF_EVEN" given'],
            'a hyphenated rounding' => [['rounding' => 'half-even'], '"half-even" given'],
            'a rounding that is not text' => [['rounding' => 1], 'int given'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidParameters')]
    public function test_invalid_parameters_block_validation(array $overrides, string $reason): void
    {
        $parameters = $this->directProportionalParameters($overrides);

        foreach ($overrides as $field => $value) {
            if ($value === null) {
                unset($parameters[$field]);
            }
        }

        $this->assertNotValidated($parameters, [], $reason);
    }

    public function test_rules_are_refused_rather_than_ignored(): void
    {
        $this->assertNotValidated($this->directProportionalParameters(), [
            'eligible' => RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '1')),
        ], 'direct-sponsor.proportional rules: this strategy takes no rules');
    }

    public function test_the_direct_sponsor_earns_the_quantity_times_the_amount_per_unit(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $entry = $this->sale($this->members['BOB'], '100.25', '2026-01-10 09:30:00', 'order:A');
        $component = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters(['unit_amount' => '0.1']), $this->plan);

        $commission = $this->monthly($component, '2026-01')->commissions()->sole();

        $this->assertSame(
            ["volume-entry:{$entry->id}:depth:1", $this->members['ALICE']->id, '10.025', '2026-01-10 09:30:00'],
            [$commission->candidate_key, $commission->member_id, $commission->amount->value(), $commission->earned_at->format('Y-m-d H:i:s')],
        );
        $this->assertSame([
            'amount' => '10.025',
            'calculation' => [
                'amount' => '10.025',
                'exact_amount' => '10.025',
                'quantity' => '100.25',
                'rounded' => false,
                'rounding' => 'half_even',
                'unit_amount' => '0.1',
            ],
            'minimum_quantity' => '1',
            'recipient' => ['depth' => 1, 'member_id' => $this->members['ALICE']->id],
            'source' => [
                'effective_at' => '2026-01-10 09:30:00',
                'member_id' => $this->members['BOB']->id,
                'quantity' => '100.25',
                'source_id' => 'ORDER:A',
                'source_type' => 'order',
                'volume_entry_id' => $entry->id,
                'volume_type' => 'sales',
            ],
            'strategy' => 'direct-sponsor.proportional',
        ], $commission->trace);
    }

    /**
     * Quantity, amount per unit, mode, and the commission it gives — or none
     * when it rounds to zero.
     *
     * @return array<string, array{string, string, string, string|null, string}>
     */
    public static function roundings(): array
    {
        return [
            'six by six, toward_zero' => ['1.234567', '2.345678', 'toward_zero', '2.895896', '2.895896651426'],
            'six by six, away_from_zero' => ['1.234567', '2.345678', 'away_from_zero', '2.895897', '2.895896651426'],
            'six by six, half_up' => ['1.234567', '2.345678', 'half_up', '2.895897', '2.895896651426'],
            'six by six, half_even' => ['1.234567', '2.345678', 'half_even', '2.895897', '2.895896651426'],
            'odd half, toward_zero' => ['0.000003', '0.5', 'toward_zero', '0.000001', '0.0000015'],
            'odd half, away_from_zero' => ['0.000003', '0.5', 'away_from_zero', '0.000002', '0.0000015'],
            'odd half, half_up' => ['0.000003', '0.5', 'half_up', '0.000002', '0.0000015'],
            'odd half, half_even' => ['0.000003', '0.5', 'half_even', '0.000002', '0.0000015'],
            'even half, toward_zero: nothing' => ['0.000001', '0.5', 'toward_zero', null, '0.0000005'],
            'even half, away_from_zero' => ['0.000001', '0.5', 'away_from_zero', '0.000001', '0.0000005'],
            'even half, half_up' => ['0.000001', '0.5', 'half_up', '0.000001', '0.0000005'],
            'even half, half_even: nothing' => ['0.000001', '0.5', 'half_even', null, '0.0000005'],
        ];
    }

    #[DataProvider('roundings')]
    public function test_the_award_is_rounded_by_the_plans_mode(string $quantity, string $unit, string $rounding, ?string $amount, string $exact): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sale($this->members['BOB'], $quantity, '2026-01-10', 'order:A');
        $component = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters(['minimum_quantity' => '0', 'unit_amount' => $unit, 'rounding' => $rounding]), $this->plan);

        $commissions = $this->monthly($component, '2026-01')->commissions()->get();

        if ($amount === null) {
            $this->assertCount(0, $commissions);

            return;
        }

        $commission = $commissions->sole();
        $this->assertSame($amount, $commission->amount->value());
        $this->assertSame(
            ['amount' => $amount, 'exact_amount' => $exact, 'rounded' => true, 'rounding' => $rounding],
            array_intersect_key($commission->trace['calculation'], array_flip(['amount', 'exact_amount', 'rounded', 'rounding'])),
        );
        $this->assertSame($commission->trace['amount'], $commission->amount->value());
    }

    public function test_every_award_is_rounded_on_its_own_never_after_adding_up(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sale($this->members['BOB'], '0.000001', '2026-01-10', 'order:A');
        $this->sale($this->members['BOB'], '0.000001', '2026-01-11', 'order:B');
        $upward = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters(['minimum_quantity' => '0', 'unit_amount' => '0.6']), $this->plan);
        $downward = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters(['minimum_quantity' => '0', 'unit_amount' => '0.4']), $this->plan);

        // 0.0000006 twice: each rounds to 0.000001, 0.000002 in all — where
        // rounding their sum, 0.0000012, would pay 0.000001.
        $this->assertSame(['0.000001', '0.000001'], $this->amounts($this->monthly($upward, '2026-01')));
        // 0.0000004 twice: each rounds to nothing — where their sum, 0.0000008,
        // would pay 0.000001.
        $this->assertSame([], $this->amounts($this->monthly($downward, '2026-01', 'run:down')));
    }

    public function test_an_award_larger_than_one_posting_fails_the_whole_run(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sale($this->members['BOB'], '10', '2026-01-09', 'order:small');
        $this->sale($this->members['BOB'], '999999999999.999999', '2026-01-10', 'order:huge');
        $component = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters(['unit_amount' => '100']), $this->plan);

        try {
            $this->monthly($component, '2026-01');
            $this->fail('An award larger than one posting was accepted.');
        } catch (InvalidCommissionCandidate $exception) {
            $this->assertStringContainsString('one ledger posting, which holds at most 9223372036854.775807', $exception->getMessage());
        }

        $this->assertSame([0, 0], [CalculationRun::query()->count(), Commission::query()->count()]);
    }

    public function test_rounding_up_past_the_largest_posting_fails_rather_than_caps(): void
    {
        // 0.000003 × 3074457345618258602.333334 is exactly the largest
        // posting plus two millionths of a millionth.
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sale($this->members['BOB'], '0.000003', '2026-01-10', 'order:A');
        $parameters = ['minimum_quantity' => '0', 'unit_amount' => '3074457345618258602.333334'];

        $down = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters([...$parameters, 'rounding' => 'toward_zero']), $this->plan);
        $this->assertSame(['9223372036854.775807'], $this->amounts($this->monthly($down, '2026-01')));

        $up = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters([...$parameters, 'rounding' => 'away_from_zero']), $this->plan);

        try {
            $this->monthly($up, '2026-01', 'run:up');
            $this->fail('An award rounded past the largest posting was accepted.');
        } catch (InvalidCommissionCandidate $exception) {
            $this->assertStringContainsString('9223372036854.775808', $exception->getMessage());
        }

        $this->assertSame(1, CalculationRun::query()->count());
    }

    public function test_the_sponsor_is_the_one_in_place_when_the_entry_took_effect(): void
    {
        $this->sale($this->members['BOB'], '10', '2026-01-05', 'order:before');
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-15 00:00:00');
        $after = $this->sale($this->members['BOB'], '10', '2026-01-20', 'order:after');
        $component = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters(), $this->plan);

        $this->assertSame([['ALICE', '12.5', 1, $after->id, '2026-01-20 00:00:00']], $this->awards($this->monthly($component, '2026-01')));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function reversals(): array
    {
        return [
            'reversed within the range' => ['2026-01-20 00:00:00', false],
            'reversed exactly at the cutoff' => ['2026-02-01 00:00:00', true],
            'reversed after the range' => ['2026-04-15 00:00:00', true],
        ];
    }

    #[DataProvider('reversals')]
    public function test_a_reversal_counts_only_before_the_runs_cutoff(string $reversedAt, bool $earns): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $entry = $this->sale($this->members['BOB'], '10', '2026-01-05', 'order:A');
        $this->reverse($entry, 'refund:A', at: CarbonImmutable::parse($reversedAt));
        $component = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters(), $this->plan);

        $this->assertSame($earns ? ['12.5'] : [], $this->amounts($this->monthly($component, '2026-01')));
        $this->assertSame([], $this->amounts($this->monthly($component, '2026-04')));
    }

    public function test_it_reads_exactly_what_the_fixed_award_reads(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sponsorAt($this->members['CAROL'], $this->members['BOB'], '2026-01-01 00:00:00');

        foreach (range(1, 12) as $i) {
            $this->sale($this->members[$i % 2 === 0 ? 'BOB' : 'CAROL'], (string) (100 + $i), "2026-01-{$i}", "order:{$i}");
        }

        $fixed = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);
        $proportional = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters(), $this->plan);
        $queries = function (callable $calculate): int {
            $count = 0;
            DB::listen(static function () use (&$count): void {
                $count++;
            });
            $calculate();

            return $count;
        };

        // Each listener counts from its own run on: the arithmetic adds no
        // query.
        $fixedQueries = $queries(fn () => $this->monthly($fixed, '2026-01', 'run:fixed'));
        $proportionalQueries = $queries(fn () => $this->monthly($proportional, '2026-01', 'run:proportional'));

        $this->assertGreaterThan(0, $fixedQueries);
        $this->assertSame($fixedQueries, $proportionalQueries);
        $this->assertSame(12, Commission::query()->where('calculation_run_id', CalculationRun::query()->where('idempotency_key', 'run:proportional')->value('id'))->count());
    }

    public function test_the_same_history_gives_the_same_trace_whatever_the_clock(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sale($this->members['BOB'], '1.234567', '2026-01-10', 'order:A');
        $component = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters(['unit_amount' => '2.345678']), $this->plan);
        $shape = static fn (CalculationRun $run): array => $run->commissions()->get()->map(static fn (Commission $commission): array => [$commission->candidate_key, json_encode($commission->trace, JSON_THROW_ON_ERROR)])->all();

        $this->travelTo(CarbonImmutable::parse('2026-02-02'));
        $first = $shape($this->monthly($component, '2026-01', 'run:a'));
        $this->travelTo(CarbonImmutable::parse('2029-09-09'));

        $this->assertSame($first, $shape($this->monthly($component, '2026-01', 'run:b')));
    }

    public function test_a_stored_rounding_corrupted_after_validation_stops_the_run(): void
    {
        $component = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters(), $this->plan);
        DB::table('mlm_plan_components')->where('id', $component->id)->update(['parameters' => json_encode([
            'strategy' => 'direct-sponsor.proportional', 'currency' => 'IDR', 'source_account' => 'commission.payable',
            'parameters' => $this->directProportionalParameters(['rounding' => 'HALF_UP']),
        ])]);

        $this->expectException(InvalidCalculationRun::class);
        $this->expectExceptionMessage('"HALF_UP" given');

        $this->monthly($component, '2026-01');
    }

    public function test_the_rounded_award_reaches_the_sponsors_wallet_exactly_once_posted(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sale($this->members['BOB'], '1.234567', '2026-01-10 09:30:00', 'order:A');
        $component = $this->fixedComponent('direct-sponsor.proportional', $this->directProportionalParameters(['unit_amount' => '2.345678', 'rounding' => 'toward_zero']), $this->plan);

        $posted = $this->poster()->post($this->approved($this->monthly($component, '2026-01')->commissions()->sole()));

        $this->assertSame('2.895896', $this->balances()->forWallet(Wallet::query()->where('member_id', $this->members['ALICE']->id)->sole())->value());
        $this->assertSame('2.895896651426', $posted->trace['calculation']['exact_amount']);
        $this->assertSame('2026-01-10 09:30:00', $posted->ledgerTransaction?->occurred_at->format('Y-m-d H:i:s'));
    }

    /**
     * @return list<string>
     */
    private function amounts(CalculationRun $run): array
    {
        return $run->commissions()->get()->map(static fn (Commission $commission): string => $commission->amount->value())->all();
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, RuleDefinition>  $rules
     */
    private function assertNotValidated(array $parameters, array $rules, string $reason): void
    {
        $this->systemAccounts()->openSystemAccount($this->plan->program, 'IDR', 'commission.payable');
        $draft = $this->draft($this->plan);
        $this->addCommissionComponent($draft, $this->commissionParameters(['strategy' => 'direct-sponsor.proportional', 'parameters' => $parameters]), rules: $rules);

        try {
            $this->lifecycle()->markValidated($draft);
            $this->fail('An invalid definition was validated.');
        } catch (InvalidPlanDefinition $exception) {
            $this->assertStringContainsString('driver "commission.strategy"', $exception->getMessage());
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        $this->assertSame(PlanVersionStatus::Draft, $draft->refresh()->status);
    }
}
