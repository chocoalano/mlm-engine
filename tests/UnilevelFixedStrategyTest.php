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
 * `unilevel.fixed`: fixed awards, per eligible original business entry, to
 * the sponsors above its member at the configured physical depths, as the
 * sponsor line stood when the entry took effect. No compression.
 */
final class UnilevelFixedStrategyTest extends DatabaseTestCase
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
        $sorted = $this->fixedComponent('unilevel.fixed', $this->unilevelParameters(), $this->plan);
        $unsorted = $this->fixedComponent('unilevel.fixed', $this->unilevelParameters(['levels' => [
            ['depth' => 3, 'amount' => '2'], ['depth' => 1, 'amount' => '10'], ['depth' => 2, 'amount' => '5'],
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
            'not a list' => [['depth' => 1, 'amount' => '10'], '"levels" is a non-empty list'],
            'text' => ['1:10,2:5', '"levels" is a non-empty list'],
            'empty' => [[], '"levels" is a non-empty list'],
            'a level that is not an object' => [[['1', '10']], '"levels"[0] is an object of depth and amount'],
            'a level of text' => [['depth 1'], '"levels"[0] is an object of depth and amount'],
            'no depth' => [[['amount' => '10']], '"levels"[0] fields are exactly amount, depth; missing: depth'],
            'no amount' => [[['depth' => 1]], 'missing: amount'],
            'an unknown level field' => [[['depth' => 1, 'amount' => '10', 'rate' => '5']], 'unknown: rate'],
            'depth zero' => [[['depth' => 0, 'amount' => '10']], '"levels"[0].depth is a whole number from 1 to 100; 0 given'],
            'a negative depth' => [[['depth' => -1, 'amount' => '10']], 'from 1 to 100; -1 given'],
            'a float depth' => [[['depth' => 1.0, 'amount' => '10']], 'from 1 to 100; float given'],
            'a depth as text' => [[['depth' => '1', 'amount' => '10']], 'from 1 to 100; string given'],
            'a boolean depth' => [[['depth' => true, 'amount' => '10']], 'from 1 to 100; bool given'],
            'a null depth' => [[['depth' => null, 'amount' => '10']], 'from 1 to 100; null given'],
            'a depth over 100' => [[['depth' => 101, 'amount' => '10']], 'from 1 to 100; 101 given'],
            'a depth twice' => [[['depth' => 2, 'amount' => '10'], ['depth' => 2, 'amount' => '5']], 'names depth 2 more than once'],
            'a zero level amount' => [[['depth' => 1, 'amount' => '0']], '"levels[0].amount" is a strictly positive award'],
            'a float level amount' => [[['depth' => 1, 'amount' => 2.5]], 'a float cannot hold most decimals exactly'],
            'a level amount too large' => [[['depth' => 1, 'amount' => '9223372036854.775808']], 'more than one ledger posting holds'],
        ];
    }

    #[DataProvider('invalidLevels')]
    public function test_invalid_levels_block_validation(mixed $levels, string $reason): void
    {
        $this->assertNotValidated($this->unilevelParameters(['levels' => $levels]), [], $reason);
    }

    public function test_the_shared_fields_are_checked_as_for_the_direct_sponsor(): void
    {
        $this->assertNotValidated($this->unilevelParameters(['minimum_quantity' => '-0.5']), [], '"minimum_quantity" is zero or more');
        $this->assertNotValidated($this->unilevelParameters(['amount' => '10']), [], 'unknown: amount');
    }

    public function test_rules_are_refused_rather_than_ignored(): void
    {
        $this->assertNotValidated($this->unilevelParameters(), [
            'eligible' => RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '1')),
        ], 'unilevel.fixed rules: this strategy takes no rules');
    }

    public function test_each_configured_depth_pays_the_sponsor_at_that_depth(): void
    {
        $entry = $this->chainAndSale();
        $component = $this->fixedComponent('unilevel.fixed', $this->unilevelParameters(), $this->plan);

        $this->assertSame([
            ['CHARLIE', '10', 1, $entry->id, '2026-01-10 00:00:00'],
            ['BOB', '5', 2, $entry->id, '2026-01-10 00:00:00'],
            ['ALICE', '2', 3, $entry->id, '2026-01-10 00:00:00'],
        ], $this->awards($this->monthly($component, '2026-01')));
    }

    public function test_depths_beyond_the_deepest_configured_earn_nothing(): void
    {
        $entry = $this->chainAndSale();
        $component = $this->fixedComponent('unilevel.fixed', $this->unilevelParameters(['levels' => [['depth' => 1, 'amount' => '10'], ['depth' => 2, 'amount' => '5']]]), $this->plan);

        $this->assertSame([
            ['CHARLIE', '10', 1, $entry->id, '2026-01-10 00:00:00'],
            ['BOB', '5', 2, $entry->id, '2026-01-10 00:00:00'],
        ], $this->awards($this->monthly($component, '2026-01')));
    }

    public function test_a_gap_in_the_depths_is_kept_not_compressed(): void
    {
        $entry = $this->chainAndSale();
        $component = $this->fixedComponent('unilevel.fixed', $this->unilevelParameters(['levels' => [['depth' => 3, 'amount' => '2'], ['depth' => 1, 'amount' => '10']]]), $this->plan);

        $this->assertSame([
            ['CHARLIE', '10', 1, $entry->id, '2026-01-10 00:00:00'],
            ['ALICE', '2', 3, $entry->id, '2026-01-10 00:00:00'],
        ], $this->awards($this->monthly($component, '2026-01')));
    }

    public function test_depths_are_read_from_the_levels_never_from_their_order(): void
    {
        $entry = $this->chainAndSale();
        $component = $this->fixedComponent('unilevel.fixed', $this->unilevelParameters(['levels' => [
            ['depth' => 3, 'amount' => '2'], ['depth' => 1, 'amount' => '10'], ['depth' => 2, 'amount' => '5'],
        ]]), $this->plan);

        $this->assertSame([
            ['CHARLIE', '10', 1, $entry->id, '2026-01-10 00:00:00'],
            ['BOB', '5', 2, $entry->id, '2026-01-10 00:00:00'],
            ['ALICE', '2', 3, $entry->id, '2026-01-10 00:00:00'],
        ], $this->awards($this->monthly($component, '2026-01')));
    }

    public function test_sponsors_who_joined_the_line_after_the_entry_earn_nothing_from_it(): void
    {
        // January: Charlie sponsors Diana, and Diana sells. February: Bob
        // sponsors Charlie, Alice sponsors Bob.
        $this->sponsorAt($this->members['DIANA'], $this->members['CHARLIE'], '2026-01-01 00:00:00');
        $january = $this->sale($this->members['DIANA'], '150', '2026-01-10', 'order:january');
        $this->sponsorAt($this->members['CHARLIE'], $this->members['BOB'], '2026-02-01 00:00:00');
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-02-01 00:00:00');
        $march = $this->sale($this->members['DIANA'], '150', '2026-03-10', 'order:march');
        $component = $this->fixedComponent('unilevel.fixed', $this->unilevelParameters(), $this->plan);
        $this->travelTo(CarbonImmutable::parse('2026-04-02'));

        $this->assertSame([['CHARLIE', '10', 1, $january->id, '2026-01-10 00:00:00']], $this->awards($this->monthly($component, '2026-01')));
        $this->assertCount(3, $this->awards($this->monthly($component, '2026-03')));
        $this->assertNotSame($january->id, $march->id);
    }

    public function test_a_short_sponsor_line_pays_only_the_depths_it_has(): void
    {
        $this->sponsorAt($this->members['DIANA'], $this->members['CHARLIE'], '2026-01-01 00:00:00');
        $this->sponsorAt($this->members['CHARLIE'], $this->members['BOB'], '2026-01-01 00:00:00');
        $entry = $this->sale($this->members['DIANA'], '150', '2026-01-10', 'order:A');
        $component = $this->fixedComponent('unilevel.fixed', $this->unilevelParameters(), $this->plan);

        $this->assertSame([
            ['CHARLIE', '10', 1, $entry->id, '2026-01-10 00:00:00'],
            ['BOB', '5', 2, $entry->id, '2026-01-10 00:00:00'],
        ], $this->awards($this->monthly($component, '2026-01')));
    }

    public function test_every_entry_pays_every_depth_on_its_own(): void
    {
        $this->chainAndSale();
        $this->sale($this->members['DIANA'], '100', '2026-01-11', 'order:B');
        $this->sale($this->members['CHARLIE'], '100', '2026-01-12', 'order:C');
        $component = $this->fixedComponent('unilevel.fixed', $this->unilevelParameters(), $this->plan);

        $awards = $this->awards($this->monthly($component, '2026-01'));

        // Diana's two entries pay three depths each; Charlie's pays two.
        $this->assertCount(8, $awards);
        $this->assertSame(['ALICE' => 3, 'BOB' => 3, 'CHARLIE' => 2], $this->sortedCounts($awards));
    }

    public function test_a_reversal_before_the_cutoff_suppresses_every_depth_and_one_at_the_cutoff_none(): void
    {
        $entry = $this->chainAndSale();
        $component = $this->fixedComponent('unilevel.fixed', $this->unilevelParameters(), $this->plan);
        $this->reverse($entry, 'refund:A', at: CarbonImmutable::parse('2026-02-01 00:00:00'));

        $this->assertCount(3, $this->awards($this->monthly($component, '2026-01')));

        $other = $this->sale($this->members['DIANA'], '150', '2026-03-05', 'order:B');
        $this->reverse($other, 'refund:B', at: CarbonImmutable::parse('2026-03-20'));

        $this->assertSame([], $this->awards($this->monthly($component, '2026-03')));
    }

    public function test_the_placement_tree_is_never_read(): void
    {
        $entry = $this->chainAndSale();
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $this->placement()->place($this->members['DIANA'], $this->members['EVE']);
        $this->travelBack();
        $component = $this->fixedComponent('unilevel.fixed', $this->unilevelParameters(), $this->plan);

        $this->assertSame(['CHARLIE', 'BOB', 'ALICE'], array_column($this->awards($this->monthly($component, '2026-01')), 0));
        $this->assertNotNull($entry->id);
    }

    /**
     * Alice sponsors Bob, Bob Charlie, Charlie Diana — on January 1 — and
     * Diana sells 150 on January 10.
     */
    private function chainAndSale(): VolumeEntry
    {
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $this->sponsorAt($this->members['CHARLIE'], $this->members['BOB'], '2026-01-01 00:00:00');
        $this->sponsorAt($this->members['DIANA'], $this->members['CHARLIE'], '2026-01-01 00:00:00');

        return $this->sale($this->members['DIANA'], '150', '2026-01-10', 'order:A');
    }

    /**
     * @param  list<array{string, string, int, string, string}>  $awards
     * @return array<string, int>
     */
    private function sortedCounts(array $awards): array
    {
        $counts = array_count_values(array_column($awards, 0));
        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, RuleDefinition>  $rules
     */
    private function assertNotValidated(array $parameters, array $rules, string $reason): void
    {
        if (! $this->systemAccounts()->openSystemAccount($this->plan->program, 'IDR', 'commission.payable')->exists) {
            $this->fail('The source account did not open.');
        }

        $draft = $this->draft($this->plan);
        $this->addCommissionComponent($draft, $this->commissionParameters(['strategy' => 'unilevel.fixed', 'parameters' => $parameters]), rules: $rules);

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
