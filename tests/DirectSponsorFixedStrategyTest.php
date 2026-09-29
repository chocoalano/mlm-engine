<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Exceptions\InvalidCalculationRun;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\Program;
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
 * `direct-sponsor.fixed`: a fixed award, per eligible original business
 * entry, to the member's direct sponsor as it was when the entry took
 * effect.
 */
final class DirectSponsorFixedStrategyTest extends DatabaseTestCase
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

    public function test_a_valid_component_validates(): void
    {
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);

        $this->assertSame(PlanVersionStatus::Validated, $component->planVersion->status);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidParameters(): array
    {
        return [
            'a missing field' => [['amount' => null], 'missing: amount'],
            'an unknown field' => [['percentage' => '5'], 'unknown: percentage'],
            'an uppercase volume type' => [['volume_type' => 'Sales'], '"volume_type": The volume type must be 1–64 lowercase letters'],
            'a volume type that is not text' => [['volume_type' => 7], '"volume_type": The volume type must be'],
            'a class name as source type' => [['source_type' => 'App\\Order'], '"source_type": The volume source type must be'],
            'a float minimum' => [['minimum_quantity' => 100.5], '"minimum_quantity": float 100.5 is not a volume quantity'],
            'a negative minimum' => [['minimum_quantity' => '-1'], '"minimum_quantity" is zero or more'],
            'a minimum no entry can store' => [['minimum_quantity' => '1000000000000'], '"minimum_quantity": A volume entry stores at most 12 integer digits'],
            'seven decimal places' => [['minimum_quantity' => '1.1234567'], 'more than 6 decimal places'],
            'a zero award' => [['amount' => '0'], '"amount" is a strictly positive award'],
            'a negative award' => [['amount' => '-10'], '"amount" is a strictly positive award'],
            'a float award' => [['amount' => 10.5], 'a float cannot hold most decimals exactly'],
            'an award too large for one posting' => [['amount' => '9223372036854.775808'], 'more than one ledger posting holds'],
            'an award with seven decimal places' => [['amount' => '0.0000001'], 'more than 6 decimal places'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidParameters')]
    public function test_invalid_parameters_block_validation(array $overrides, string $reason): void
    {
        $parameters = $this->directParameters($overrides);

        if (array_key_exists('amount', $overrides) && $overrides['amount'] === null) {
            unset($parameters['amount']);
        }

        $this->assertNotValidated($parameters, [], $reason);
    }

    public function test_rules_are_refused_rather_than_ignored(): void
    {
        $this->assertNotValidated($this->directParameters(), [
            'eligible' => RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '1')),
        ], 'direct-sponsor.fixed rules: this strategy takes no rules');
    }

    public function test_the_direct_sponsor_of_an_eligible_entry_earns_the_fixed_award(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $entry = $this->sale($this->members['BOB'], '150', '2026-01-10 09:30:00', 'order:A');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);

        $commission = $this->monthly($component, '2026-01')->commissions()->sole();

        $this->assertSame(
            ["volume-entry:{$entry->id}:depth:1", $this->members['ALICE']->id, '10', 'IDR', '2026-01-10 09:30:00', CommissionStatus::Calculated],
            [$commission->candidate_key, $commission->member_id, $commission->amount->value(), $commission->currency, $commission->earned_at->format('Y-m-d H:i:s'), $commission->status],
        );
        $this->assertSame([
            'amount' => '10',
            'minimum_quantity' => '100',
            'recipient' => ['depth' => 1, 'member_id' => $this->members['ALICE']->id],
            'source' => [
                'effective_at' => '2026-01-10 09:30:00',
                'member_id' => $this->members['BOB']->id,
                'quantity' => '150',
                'source_id' => 'ORDER:A',
                'source_type' => 'order',
                'volume_entry_id' => $entry->id,
                'volume_type' => 'sales',
            ],
            'strategy' => 'direct-sponsor.fixed',
        ], $commission->trace);
    }

    public function test_only_entries_of_the_configured_type_and_source_count(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sale($this->members['BOB'], '500', '2026-01-10', 'refund-type', type: 'returns');
        $this->sale($this->members['BOB'], '500', '2026-01-11', 'subscription-source', sourceType: 'subscription');
        $eligible = $this->sale($this->members['BOB'], '500', '2026-01-12', 'eligible');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);

        $this->assertSame([['ALICE', '10', 1, $eligible->id, '2026-01-12 00:00:00']], $this->awards($this->monthly($component, '2026-01')));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function thresholds(): array
    {
        return [
            'just below' => ['100', '99.999999', false],
            'exactly' => ['100', '100', true],
            'just above' => ['100', '100.000001', true],
            'below a fractional minimum' => ['100.5', '100.499999', false],
            'exactly a fractional minimum' => ['100.5', '100.5', true],
            'no minimum' => ['0', '0.000001', true],
        ];
    }

    #[DataProvider('thresholds')]
    public function test_the_minimum_quantity_is_compared_exactly(string $minimum, string $quantity, bool $earns): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sale($this->members['BOB'], $quantity, '2026-01-10', 'order:A');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(['minimum_quantity' => $minimum]), $this->plan);

        $this->assertCount($earns ? 1 : 0, $this->awards($this->monthly($component, '2026-01')));
    }

    public function test_every_eligible_entry_earns_its_own_award(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $entries = [
            $this->sale($this->members['BOB'], '100', '2026-01-10', 'order:A'),
            $this->sale($this->members['BOB'], '200', '2026-01-11', 'order:B'),
            $this->sale($this->members['BOB'], '300', '2026-01-12', 'order:C'),
        ];
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);

        $commissions = $this->monthly($component, '2026-01')->commissions()->get();

        $this->assertCount(3, $commissions);
        $this->assertEqualsCanonicalizing(
            array_map(static fn ($entry): string => "volume-entry:{$entry->id}:depth:1", $entries),
            $commissions->pluck('candidate_key')->all(),
        );
        $this->assertSame(['10', '10', '10'], $commissions->map(static fn (Commission $commission): string => $commission->amount->value())->all());
    }

    public function test_the_sponsor_is_the_one_in_place_when_the_entry_took_effect(): void
    {
        $before = $this->sale($this->members['BOB'], '150', '2026-01-05', 'order:before');
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-02-01 00:00:00');
        $after = $this->sale($this->members['BOB'], '150', '2026-02-10', 'order:after');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);

        $run = $this->calculate($component, '2026-01-01 00:00:00', '2026-03-01 00:00:00');

        $this->assertSame([['ALICE', '10', 1, $after->id, '2026-02-10 00:00:00']], $this->awards($run));
        $this->assertNotSame($before->id, $after->id);
    }

    public function test_a_sponsor_assigned_after_the_entry_earns_nothing_from_it(): void
    {
        // January: Bob sells with no sponsor. March: Alice sponsors Bob.
        // April: January is calculated.
        $this->sale($this->members['BOB'], '150', '2026-01-10', 'order:A');
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-03-01 00:00:00');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);
        $this->travelTo(CarbonImmutable::parse('2026-04-02'));

        $this->assertSame([], $this->awards($this->monthly($component, '2026-01')));
        $this->assertTrue($this->genealogy()->directSponsor($this->members['BOB'])?->is($this->members['ALICE']));
    }

    public function test_a_sponsorship_effective_in_the_same_second_counts(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-10 12:00:00');
        $this->sale($this->members['BOB'], '150', '2026-01-10 12:00:00', 'order:A');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);

        $this->assertSame('ALICE', $this->awards($this->monthly($component, '2026-01'))[0][0] ?? null);
    }

    public function test_a_member_without_a_sponsor_earns_nobody_anything_and_never_themselves(): void
    {
        $this->sale($this->members['ALICE'], '150', '2026-01-10', 'order:root');
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);

        $this->assertSame([], $this->awards($this->monthly($component, '2026-01')));
    }

    public function test_entries_of_other_programs_and_ranges_do_not_count(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sale($this->members['BOB'], '150', '2025-12-31 23:59:59', 'order:december');
        $this->sale($this->members['BOB'], '150', '2026-02-01 00:00:00', 'order:february');
        $first = $this->sale($this->members['BOB'], '150', '2026-01-01 00:00:00', 'order:first');
        $elsewhere = $this->members(Program::factory()->create(), 'X', 'Y');
        $this->sponsorAt($elsewhere['Y'], $elsewhere['X'], '2026-01-01 00:00:00');
        $this->sale($elsewhere['Y'], '150', '2026-01-10', 'order:elsewhere');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);

        $this->assertSame([['ALICE', '10', 1, $first->id, '2026-01-01 00:00:00']], $this->awards($this->monthly($component, '2026-01')));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function reversals(): array
    {
        return [
            'reversed within the range' => ['2026-01-20 00:00:00', false],
            'reversed one second before the cutoff' => ['2026-01-31 23:59:59', false],
            'reversed exactly at the cutoff' => ['2026-02-01 00:00:00', true],
            'reversed after the range' => ['2026-04-15 00:00:00', true],
        ];
    }

    /**
     * An entry reversed before the run's cutoff earns nothing; a reversal at
     * or after the cutoff belongs to a later range and changes nothing here.
     */
    #[DataProvider('reversals')]
    public function test_a_reversal_counts_only_before_the_runs_cutoff(string $reversedAt, bool $earns): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $entry = $this->sale($this->members['BOB'], '150', '2026-01-05', 'order:A');
        $this->reverse($entry, 'refund:A', at: CarbonImmutable::parse($reversedAt));
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);

        $this->assertCount($earns ? 1 : 0, $this->awards($this->monthly($component, '2026-01')));
    }

    public function test_a_later_reversal_claws_nothing_back(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $entry = $this->sale($this->members['BOB'], '150', '2026-01-05', 'order:A');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);
        $january = $this->monthly($component, '2026-01');

        $this->reverse($entry, 'refund:A', at: CarbonImmutable::parse('2026-04-10'));

        // April holds only the reversal: no candidate, and nothing negative.
        $this->assertSame([], $this->awards($this->monthly($component, '2026-04')));
        // January, recalculated under a new key, still pays as of its cutoff;
        // the January run stored before is untouched.
        $this->assertCount(1, $this->awards($this->monthly($component, '2026-01', 'run:2026-01-again')));
        $this->assertSame(CommissionStatus::Calculated, $january->commissions()->sole()->status);
        $this->assertSame(0, Commission::query()->where('amount_millionths', '<', 0)->count());
    }

    public function test_the_placement_tree_is_never_read(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $this->placement()->place($this->members['BOB'], $this->members['CAROL']);
        $this->travelBack();
        $this->sale($this->members['BOB'], '150', '2026-01-10', 'order:A');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);

        $this->assertSame('ALICE', $this->awards($this->monthly($component, '2026-01'))[0][0] ?? null);
    }

    public function test_a_replay_returns_the_stored_run_and_does_not_calculate_again(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sale($this->members['BOB'], '150', '2026-01-10', 'order:A');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);
        $run = $this->monthly($component, '2026-01');

        // A new eligible entry: a calculation would pay it; a replay does not.
        $this->sale($this->members['BOB'], '150', '2026-01-20', 'order:B');

        $this->assertSame($run->id, $this->monthly($component, '2026-01')->id);
        $this->assertSame(1, Commission::query()->count());
        $this->assertCount(2, $this->awards($this->monthly($component, '2026-01', 'run:2026-01-b')));
    }

    public function test_the_same_history_gives_the_same_candidates(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sponsorAt($this->members['CAROL'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sale($this->members['CAROL'], '150', '2026-01-12', 'order:C');
        $this->sale($this->members['BOB'], '150', '2026-01-10', 'order:B');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);

        $shape = static fn (CalculationRun $run): array => $run->commissions()->get()->map(static fn (Commission $commission): array => [$commission->candidate_key, $commission->trace, $commission->earned_at->format('Y-m-d H:i:s')])->all();

        $this->travelTo(CarbonImmutable::parse('2026-02-02'));
        $first = $shape($this->monthly($component, '2026-01', 'run:a'));
        $this->travelTo(CarbonImmutable::parse('2027-05-05'));

        $this->assertSame($first, $shape($this->monthly($component, '2026-01', 'run:b')));
    }

    public function test_calculating_reads_only(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $entry = $this->sale($this->members['BOB'], '150', '2026-01-10', 'order:A');
        $this->reverse($this->sale($this->members['BOB'], '150', '2026-01-11', 'order:B'), 'refund:B', at: CarbonImmutable::parse('2026-01-15'));
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);
        $before = $this->readableRows();

        $this->monthly($component, '2026-01');

        $this->assertSame($before, $this->readableRows());
        $this->assertSame([1, 1], [CalculationRun::query()->count(), Commission::query()->count()]);
        $this->assertNotNull($entry->id);
    }

    public function test_a_stored_definition_corrupted_after_validation_stops_the_run(): void
    {
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), $this->plan);
        DB::table('mlm_plan_components')->where('id', $component->id)->update(['parameters' => json_encode([
            'strategy' => 'direct-sponsor.fixed', 'currency' => 'IDR', 'source_account' => 'commission.payable',
            'parameters' => $this->directParameters(['amount' => '-10']),
        ])]);

        $this->expectException(InvalidCalculationRun::class);
        $this->expectExceptionMessage('"amount" is a strictly positive award');

        $this->monthly($component, '2026-01');
    }

    public function test_the_award_reaches_the_sponsors_wallet_exactly_once_posted(): void
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sale($this->members['BOB'], '150', '2026-01-10 09:30:00', 'order:A');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(['amount' => '12.345678']), $this->plan);

        $commission = $this->monthly($component, '2026-01')->commissions()->sole();
        $this->assertSame(0, Wallet::query()->count());

        $posted = $this->poster()->post($this->approved($commission));

        $this->assertSame('12.345678', $this->balances()->forWallet(Wallet::query()->where('member_id', $this->members['ALICE']->id)->sole())->value());
        $this->assertSame('2026-01-10 09:30:00', $posted->ledgerTransaction?->occurred_at->format('Y-m-d H:i:s'));
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, RuleDefinition>  $rules
     */
    private function assertNotValidated(array $parameters, array $rules, string $reason): void
    {
        $this->systemAccounts()->openSystemAccount($this->plan->program, 'IDR', 'commission.payable');
        $draft = $this->draft($this->plan);
        $this->addCommissionComponent($draft, $this->commissionParameters(['strategy' => 'direct-sponsor.fixed', 'parameters' => $parameters]), rules: $rules);

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
