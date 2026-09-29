<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\VolumeEntry;
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
 * `unilevel.proportional`: sponsors at configured physical depths, as the
 * line stood at the entry's moment, earn the entry's quantity times their
 * depth's amount per unit — each entry and depth rounded on its own by the
 * component's one mode. No compression.
 */
final class UnilevelProportionalStrategyTest extends DatabaseTestCase
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
        $this->members = $this->members($this->plan->program, 'ALICE', 'BOB', 'CHARLIE', 'DIANA', 'EVE');
    }

    public function test_a_valid_component_validates_whatever_order_its_levels_are_listed_in(): void
    {
        $sorted = $this->fixedComponent('unilevel.proportional', $this->unilevelProportionalParameters(), $this->plan);
        $unsorted = $this->fixedComponent('unilevel.proportional', $this->unilevelProportionalParameters(['levels' => [
            ['depth' => 3, 'unit_amount' => '0.25'], ['depth' => 1, 'unit_amount' => '2'], ['depth' => 2, 'unit_amount' => '1.5'],
        ]]), $this->plan);

        $this->assertSame(PlanVersionStatus::Validated, $sorted->planVersion->status);
        $this->assertSame(PlanVersionStatus::Validated, $unsorted->planVersion->status);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidLevels(): array
    {
        return [
            'not a list' => [['depth' => 1, 'unit_amount' => '2'], '"levels" is a non-empty list of {depth, unit_amount}'],
            'empty' => [[], '"levels" is a non-empty list'],
            'no depth' => [[['unit_amount' => '2']], 'missing: depth'],
            'no unit amount' => [[['depth' => 1]], 'missing: unit_amount'],
            'a fixed amount instead' => [[['depth' => 1, 'amount' => '2']], 'missing: unit_amount; unknown: amount'],
            'a rounding per level' => [[['depth' => 1, 'unit_amount' => '2', 'rounding' => 'half_up']], 'unknown: rounding'],
            'a depth as text' => [[['depth' => '1', 'unit_amount' => '2']], 'from 1 to 100; string given'],
            'a float depth' => [[['depth' => 1.0, 'unit_amount' => '2']], 'from 1 to 100; float given'],
            'depth zero' => [[['depth' => 0, 'unit_amount' => '2']], 'from 1 to 100; 0 given'],
            'a negative depth' => [[['depth' => -2, 'unit_amount' => '2']], 'from 1 to 100; -2 given'],
            'a depth over 100' => [[['depth' => 101, 'unit_amount' => '2']], 'from 1 to 100; 101 given'],
            'a depth twice' => [[['depth' => 1, 'unit_amount' => '2'], ['depth' => 1, 'unit_amount' => '1']], 'names depth 1 more than once'],
            'a zero unit amount' => [[['depth' => 1, 'unit_amount' => '0']], '"levels[0].unit_amount" is strictly positive'],
            'a negative unit amount' => [[['depth' => 1, 'unit_amount' => '-2']], '"levels[0].unit_amount" is strictly positive'],
            'a float unit amount' => [[['depth' => 1, 'unit_amount' => 2.5]], 'a float cannot hold most decimals exactly'],
        ];
    }

    #[DataProvider('invalidLevels')]
    public function test_invalid_levels_block_validation(mixed $levels, string $reason): void
    {
        $this->assertNotValidated($this->unilevelProportionalParameters(['levels' => $levels]), [], $reason);
    }

    public function test_one_rounding_mode_is_required_for_every_depth(): void
    {
        $parameters = $this->unilevelProportionalParameters();
        unset($parameters['rounding']);

        $this->assertNotValidated($parameters, [], 'missing: rounding');
        $this->assertNotValidated($this->unilevelProportionalParameters(['rounding' => 'round_half_up']), [], '"round_half_up" given');
    }

    public function test_rules_are_refused_rather_than_ignored(): void
    {
        $this->assertNotValidated($this->unilevelProportionalParameters(), [
            'eligible' => RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '1')),
        ], 'unilevel.proportional rules: this strategy takes no rules');
    }

    public function test_each_configured_depth_earns_the_quantity_times_its_amount_per_unit(): void
    {
        $entry = $this->chainAndSale('10');
        $component = $this->fixedComponent('unilevel.proportional', $this->unilevelProportionalParameters(), $this->plan);

        $run = $this->monthly($component, '2026-01');

        $this->assertSame([
            ['CHARLIE', '20', 1, $entry->id, '2026-01-10 00:00:00'],
            ['BOB', '15', 2, $entry->id, '2026-01-10 00:00:00'],
            ['ALICE', '2.5', 3, $entry->id, '2026-01-10 00:00:00'],
        ], $this->awards($run));
        $this->assertSame(
            ['amount' => '2.5', 'exact_amount' => '2.5', 'quantity' => '10', 'rounded' => false, 'rounding' => 'half_even', 'unit_amount' => '0.25'],
            $run->commissions()->get()->firstWhere('candidate_key', "volume-entry:{$entry->id}:depth:3")?->trace['calculation'],
        );
    }

    public function test_one_depth_can_round_to_nothing_while_the_others_earn(): void
    {
        $entry = $this->chainAndSale('0.000001');
        $component = $this->fixedComponent('unilevel.proportional', $this->unilevelProportionalParameters(['minimum_quantity' => '0', 'levels' => [
            ['depth' => 1, 'unit_amount' => '0.6'],   // 0.0000006: rounds to 0.000001
            ['depth' => 2, 'unit_amount' => '0.4'],   // 0.0000004: rounds to nothing
            ['depth' => 3, 'unit_amount' => '2'],     // 0.000002: exact
        ]]), $this->plan);

        $this->assertSame([
            ['CHARLIE', '0.000001', 1, $entry->id, '2026-01-10 00:00:00'],
            ['ALICE', '0.000002', 3, $entry->id, '2026-01-10 00:00:00'],
        ], $this->awards($this->monthly($component, '2026-01')));
    }

    public function test_a_gap_in_the_depths_is_kept_not_compressed(): void
    {
        $entry = $this->chainAndSale('10');
        $component = $this->fixedComponent('unilevel.proportional', $this->unilevelProportionalParameters(['levels' => [
            ['depth' => 3, 'unit_amount' => '0.25'], ['depth' => 1, 'unit_amount' => '2'],
        ]]), $this->plan);

        $this->assertSame([
            ['CHARLIE', '20', 1, $entry->id, '2026-01-10 00:00:00'],
            ['ALICE', '2.5', 3, $entry->id, '2026-01-10 00:00:00'],
        ], $this->awards($this->monthly($component, '2026-01')));
    }

    public function test_depths_are_read_from_the_levels_never_from_their_order(): void
    {
        $entry = $this->chainAndSale('10');
        $component = $this->fixedComponent('unilevel.proportional', $this->unilevelProportionalParameters(['levels' => [
            ['depth' => 3, 'unit_amount' => '0.25'], ['depth' => 1, 'unit_amount' => '2'], ['depth' => 2, 'unit_amount' => '1.5'],
        ]]), $this->plan);

        $this->assertSame(['CHARLIE' => '20', 'BOB' => '15', 'ALICE' => '2.5'], array_column($this->awards($this->monthly($component, '2026-01')), 1, 0));
        $this->assertNotNull($entry->id);
    }

    public function test_sponsors_who_joined_the_line_after_the_entry_earn_nothing_from_it(): void
    {
        $this->sponsorAt($this->members['DIANA'], $this->members['CHARLIE'], '2026-01-01 00:00:00');
        $entry = $this->sale($this->members['DIANA'], '10', '2026-01-10', 'order:january');
        $this->sponsorAt($this->members['CHARLIE'], $this->members['BOB'], '2026-02-01 00:00:00');
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-02-01 00:00:00');
        $component = $this->fixedComponent('unilevel.proportional', $this->unilevelProportionalParameters(), $this->plan);
        $this->travelTo(CarbonImmutable::parse('2026-04-02'));

        $this->assertSame([['CHARLIE', '20', 1, $entry->id, '2026-01-10 00:00:00']], $this->awards($this->monthly($component, '2026-01')));
    }

    public function test_a_reversal_before_the_cutoff_suppresses_every_depth(): void
    {
        $entry = $this->chainAndSale('10');
        $this->reverse($entry, 'refund:A', at: CarbonImmutable::parse('2026-01-20'));
        $component = $this->fixedComponent('unilevel.proportional', $this->unilevelProportionalParameters(), $this->plan);

        $this->assertSame([], $this->awards($this->monthly($component, '2026-01')));
    }

    public function test_the_placement_tree_is_never_read(): void
    {
        $this->chainAndSale('10');
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $this->placement()->place($this->members['DIANA'], $this->members['EVE']);
        $this->travelBack();
        $component = $this->fixedComponent('unilevel.proportional', $this->unilevelProportionalParameters(), $this->plan);

        $this->assertSame(['CHARLIE', 'BOB', 'ALICE'], array_column($this->awards($this->monthly($component, '2026-01')), 0));
    }

    /**
     * Alice sponsors Bob, Bob Charlie, Charlie Diana — on January 1 — and
     * Diana sells on January 10.
     */
    private function chainAndSale(string $quantity): VolumeEntry
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sponsorAt($this->members['CHARLIE'], $this->members['BOB'], '2026-01-01 00:00:00');
        $this->sponsorAt($this->members['DIANA'], $this->members['CHARLIE'], '2026-01-01 00:00:00');

        return $this->sale($this->members['DIANA'], $quantity, '2026-01-10', 'order:A');
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, RuleDefinition>  $rules
     */
    private function assertNotValidated(array $parameters, array $rules, string $reason): void
    {
        $this->systemAccounts()->openSystemAccount($this->plan->program, 'IDR', 'commission.payable');
        $draft = $this->draft($this->plan);
        $this->addCommissionComponent($draft, $this->commissionParameters(['strategy' => 'unilevel.proportional', 'parameters' => $parameters]), rules: $rules);

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
