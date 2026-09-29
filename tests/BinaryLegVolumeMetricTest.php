<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Exceptions\CorruptBinaryPlacement;
use PandaBear\Mlm\Exceptions\InvalidMetricParameters;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Qualification\QualificationContext;
use PandaBear\Mlm\Qualification\QualificationEngine;
use PandaBear\Mlm\Rank\RankContext;
use PandaBear\Mlm\Rank\RankEngine;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `binary.left.volume` and `binary.right.volume`: the volume of one leg — the
 * binary child on that side and its binary subtree — each entry counted only
 * if its member was in that leg when the activity happened. A side assigned
 * or a subtree adopted later never captures earlier activity, and a reversal
 * lands where the activity it corrects landed.
 */
final class BinaryLegVolumeMetricTest extends DatabaseTestCase
{
    use BuildsGenealogies;
    use BuildsPlanDefinitions;
    use RecordsVolume;

    /**
     * @var array<string, Member>
     */
    private array $members;

    protected function setUp(): void
    {
        parent::setUp();

        $this->members = $this->members(Program::factory()->create(), 'N', 'A', 'B', 'C', 'D', 'E', 'X');
    }

    /**
     * @return array<string, array{BinarySide}>
     */
    public static function sides(): array
    {
        return ['left' => [BinarySide::Left], 'right' => [BinarySide::Right]];
    }

    #[DataProvider('sides')]
    public function test_an_empty_side_is_an_empty_leg(BinarySide $side): void
    {
        $this->record($this->members['N'], '50', 'own', at: $this->at('2026-02-01'));

        $this->assertSame('0', $this->leg($side, 'N'));

        // The other side taken leaves this one empty.
        $this->link('2026-01-01', 'N', 'A', $this->other($side));
        $this->record($this->members['A'], '10', 'a', at: $this->at('2026-02-01'));

        $this->assertSame('0', $this->leg($side, 'N'));
        $this->assertSame('10', $this->leg($this->other($side), 'N'));
    }

    #[DataProvider('sides')]
    public function test_a_leg_is_its_child_and_the_childs_binary_subtree_and_nothing_else(BinarySide $side): void
    {
        // N: this side A > (left) C > (right) E; the other side B > (left) D.
        // X is placed under A generically only.
        $this->link('2026-01-01', 'N', 'A', $side);
        $this->link('2026-01-01', 'N', 'B', $this->other($side));
        $this->link('2026-01-01', 'A', 'C', BinarySide::Left);
        $this->link('2026-01-01', 'C', 'E', BinarySide::Right);
        $this->link('2026-01-01', 'B', 'D', BinarySide::Left);
        $this->placement()->place($this->members['X'], $this->members['A']);

        foreach (['N' => '1000', 'A' => '1', 'C' => '20', 'E' => '300', 'B' => '4000', 'D' => '50000', 'X' => '600000'] as $code => $quantity) {
            $this->record($this->members[$code], $quantity, "sale-{$code}", at: $this->at('2026-02-01'));
        }

        $this->assertSame('321', $this->leg($side, 'N'));
        $this->assertSame('54000', $this->leg($this->other($side), 'N'));
        $this->assertSame('320', $this->leg(BinarySide::Left, 'A'));
        $this->assertSame('0', $this->leg(BinarySide::Right, 'A'));

        // The generic network does include X.
        $this->assertSame('600320', $this->engine()->resolve('placement.network.volume', new MetricContext($this->members['A'], ['type' => 'sales']))->value());
    }

    #[DataProvider('sides')]
    public function test_a_generic_subtree_stays_out_of_the_leg_its_root_is_adopted_into(BinarySide $side): void
    {
        // N > A > B, both generic; A > B is never given a side. March: N > A
        // is adopted. April: A and B sell.
        $this->travelTo($this->at('2026-01-01'));
        $edge = $this->placement()->place($this->members['A'], $this->members['N']);
        $this->placement()->place($this->members['B'], $this->members['A']);
        $this->travelTo($this->at('2026-03-01'));
        $this->binary()->adopt($edge, $side);
        $this->record($this->members['A'], '3', 'a', at: $this->at('2026-04-10'));
        $this->record($this->members['B'], '400', 'b', at: $this->at('2026-04-10'));

        $this->assertSame('3', $this->leg($side, 'N'));
        $this->assertSame('403', $this->engine()->resolve('placement.network.volume', new MetricContext($this->members['N'], ['type' => 'sales']))->value());
    }

    #[DataProvider('sides')]
    public function test_a_maximum_depth_counts_from_the_member(BinarySide $side): void
    {
        // N > A on this side, then A > B > C > D.
        $this->link('2026-01-01', 'N', 'A', $side);
        $this->link('2026-01-01', 'A', 'B', BinarySide::Left);
        $this->link('2026-01-01', 'B', 'C', BinarySide::Right);
        $this->link('2026-01-01', 'C', 'D', BinarySide::Left);

        foreach (['A' => '1', 'B' => '20', 'C' => '300', 'D' => '4000'] as $code => $quantity) {
            $this->record($this->members[$code], $quantity, "sale-{$code}", at: $this->at('2026-02-01'));
        }

        $this->assertSame(
            ['1', '21', '321', '4321', '4321'],
            [$this->leg($side, 'N', maxDepth: 1), $this->leg($side, 'N', maxDepth: 2), $this->leg($side, 'N', maxDepth: 3), $this->leg($side, 'N', maxDepth: 4), $this->leg($side, 'N')],
        );
    }

    #[DataProvider('sides')]
    public function test_only_the_requested_type_counts(BinarySide $side): void
    {
        $this->link('2026-01-01', 'N', 'A', $side);
        $this->record($this->members['A'], '10', 'sale', at: $this->at('2026-02-01'));
        $this->record($this->members['A'], '99', 'bonus', type: 'bonus_points', at: $this->at('2026-02-01'));

        $this->assertSame(['10', '99'], [$this->leg($side, 'N'), $this->leg($side, 'N', type: 'bonus_points')]);
    }

    #[DataProvider('sides')]
    public function test_the_period_includes_its_start_and_excludes_its_end(BinarySide $side): void
    {
        $this->link('2026-01-01', 'N', 'A', $side);
        $this->record($this->members['A'], '1', 'start', at: $this->at('2026-02-01 00:00:00'));
        $this->record($this->members['A'], '20', 'inside', at: $this->at('2026-02-14 12:00:00'));
        $this->record($this->members['A'], '300', 'end', at: $this->at('2026-03-01 00:00:00'));

        $this->assertSame('21', $this->leg($side, 'N', from: '2026-02-01 00:00:00', until: '2026-03-01 00:00:00'));
        $this->assertSame('320', $this->leg($side, 'N', from: '2026-02-01 00:00:01'));
        $this->assertSame('1', $this->leg($side, 'N', until: '2026-02-01 00:00:01'));
    }

    #[DataProvider('sides')]
    public function test_a_side_assigned_later_never_captures_earlier_activity(BinarySide $side): void
    {
        // January: A is placed under N generically, and sells. March: that
        // edge is adopted. April: A sells again.
        $this->travelTo($this->at('2026-01-01'));
        $edge = $this->placement()->place($this->members['A'], $this->members['N']);
        $this->record($this->members['A'], '100', 'jan', at: $this->at('2026-01-15'));
        $this->travelTo($this->at('2026-03-01'));
        $this->binary()->adopt($edge, $side);
        $this->record($this->members['A'], '7', 'apr', at: $this->at('2026-04-15'));

        $this->assertSame('0', $this->leg($side, 'N', from: '2026-01-01', until: '2026-02-01'));
        $this->assertSame('7', $this->leg($side, 'N', from: '2026-04-01', until: '2026-05-01'));
        $this->assertSame('7', $this->leg($side, 'N'));
        $this->assertSame('107', $this->engine()->resolve('placement.network.volume', new MetricContext($this->members['N'], ['type' => 'sales']))->value());
    }

    #[DataProvider('sides')]
    public function test_a_subtree_adopted_later_joins_the_leg_from_its_adoption(BinarySide $side): void
    {
        // January: N > A on this side; A > B generic only. February: B sells.
        // April: A > B adopted. May: B sells again.
        $this->link('2026-01-01', 'N', 'A', $side);
        $edge = $this->placement()->place($this->members['B'], $this->members['A']);
        $this->record($this->members['B'], '100', 'feb', at: $this->at('2026-02-10'));
        $this->travelTo($this->at('2026-04-01'));
        $this->binary()->adopt($edge, BinarySide::Right);
        $this->record($this->members['B'], '5', 'may', at: $this->at('2026-05-10'));

        $this->assertSame('5', $this->leg($side, 'N'));
        $this->assertSame('0', $this->leg($side, 'N', from: '2026-02-01', until: '2026-03-01'));
        $this->assertSame('5', $this->leg(BinarySide::Right, 'A'));
    }

    #[DataProvider('sides')]
    public function test_a_child_whose_own_binary_tree_is_older_joins_the_leg_only_from_its_side(BinarySide $side): void
    {
        // January: A > B, binary; A is placed under N generically. February:
        // A and B sell. March: N > A adopted. April: A and B sell again.
        $this->travelTo($this->at('2026-01-01'));
        $edge = $this->placement()->place($this->members['A'], $this->members['N']);
        $this->binary()->place($this->members['B'], $this->members['A'], BinarySide::Left);
        $this->record($this->members['A'], '100', 'a-feb', at: $this->at('2026-02-10'));
        $this->record($this->members['B'], '200', 'b-feb', at: $this->at('2026-02-10'));
        $this->travelTo($this->at('2026-03-01'));
        $this->binary()->adopt($edge, $side);
        $this->record($this->members['A'], '3', 'a-apr', at: $this->at('2026-04-10'));
        $this->record($this->members['B'], '4', 'b-apr', at: $this->at('2026-04-10'));

        $this->assertSame('7', $this->leg($side, 'N'));
        $this->assertSame('204', $this->leg(BinarySide::Left, 'A'));
    }

    #[DataProvider('sides')]
    public function test_a_reversal_of_activity_from_before_the_side_counts_for_neither(BinarySide $side): void
    {
        // January: A sells while placed only generically. March: adopted.
        // April: the January sale is reversed.
        $this->travelTo($this->at('2026-01-01'));
        $edge = $this->placement()->place($this->members['A'], $this->members['N']);
        $sale = $this->record($this->members['A'], '100', 'jan', at: $this->at('2026-01-15'));
        $this->travelTo($this->at('2026-03-01'));
        $this->binary()->adopt($edge, $side);
        $this->reverse($sale, 'jan-refund', at: $this->at('2026-04-10'));

        $this->assertSame('0', $this->leg($side, 'N'));
        $this->assertSame('0', $this->leg($side, 'N', from: '2026-04-01', until: '2026-05-01'));
        $this->assertSame('-100', $this->engine()->resolve('member.volume', new MetricContext($this->members['A'], ['type' => 'sales'], $this->at('2026-04-01'), $this->at('2026-05-01')))->value());
    }

    #[DataProvider('sides')]
    public function test_a_reversal_is_clawed_back_in_its_own_period_from_the_same_leg(BinarySide $side): void
    {
        $this->link('2026-01-01', 'N', 'A', $side);
        $sale = $this->record($this->members['A'], '100', 'feb', at: $this->at('2026-02-10'));
        $this->reverse($sale, 'feb-refund', at: $this->at('2026-04-10'));

        $this->assertSame('100', $this->leg($side, 'N', from: '2026-02-01', until: '2026-03-01'));
        $this->assertSame('-100', $this->leg($side, 'N', from: '2026-04-01', until: '2026-05-01'));
        $this->assertSame('0', $this->leg($side, 'N'));
        $this->assertSame('0', $this->leg($this->other($side), 'N', from: '2026-04-01', until: '2026-05-01'));
    }

    #[DataProvider('sides')]
    public function test_an_activity_and_its_reversal_in_one_period_net_out(BinarySide $side): void
    {
        $this->link('2026-01-01', 'N', 'A', $side);
        $sale = $this->record($this->members['A'], '100', 'feb', at: $this->at('2026-02-10'));
        $this->record($this->members['A'], '8', 'feb-kept', at: $this->at('2026-02-11'));
        $this->reverse($sale, 'feb-refund', at: $this->at('2026-02-20'));

        $this->assertSame('8', $this->leg($side, 'N', from: '2026-02-01', until: '2026-03-01'));
    }

    #[DataProvider('sides')]
    public function test_values_are_exact(BinarySide $side): void
    {
        $this->link('2026-01-01', 'N', 'A', $side);
        $this->link('2026-01-01', 'A', 'B', BinarySide::Left);
        $this->record($this->members['A'], '0.1', 'a1', at: $this->at('2026-02-01'));
        $this->record($this->members['A'], '0.1', 'a2', at: $this->at('2026-02-01'));
        $this->record($this->members['B'], '0.000001', 'b', at: $this->at('2026-02-01'));

        $this->assertSame('0.200001', $this->leg($side, 'N'));
    }

    #[DataProvider('sides')]
    public function test_a_leg_beyond_a_64_bit_count_of_millionths_is_exact(BinarySide $side): void
    {
        if ($this->app->make('db')->connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite overflows SUM past 64 bits (ADR-010); MySQL and PostgreSQL widen it.');
        }

        // A line of ten below N's child, each selling the largest quantity.
        $this->link('2026-01-01', 'N', 'A', $side);
        $parent = 'A';

        foreach (range(1, 9) as $i) {
            $this->members["M{$i}"] = Member::factory()->for($this->members['N']->program)->create(['member_code' => "M{$i}"]);
            $this->link('2026-01-01', $parent, "M{$i}", BinarySide::Left);
            $parent = "M{$i}";
        }

        foreach (['A', ...array_map(static fn (int $i): string => "M{$i}", range(1, 9))] as $code) {
            $this->record($this->members[$code], '999999999999.999999', "large-{$code}", at: $this->at('2026-02-01'));
        }

        $this->assertSame('9999999999999.99999', $this->leg($side, 'N'));
    }

    public function test_it_reads_the_stored_member_not_the_instances_program(): void
    {
        $this->link('2026-01-01', 'N', 'A', BinarySide::Left);
        $this->record($this->members['A'], '10', 'a', at: $this->at('2026-02-01'));

        // An unsaved change claiming another program.
        $this->members['N']->program_id = Program::factory()->create()->id;

        $this->assertSame('10', $this->leg(BinarySide::Left, 'N'));
    }

    public function test_volume_of_another_program_never_counts_even_through_a_raw_path(): void
    {
        $outsider = Member::factory()->create(['member_code' => 'Outsider']);
        $this->link('2026-01-01', 'N', 'A', BinarySide::Left);
        $this->record($this->members['A'], '10', 'a', at: $this->at('2026-02-01'));
        $this->record($outsider, '999', 'outsider', at: $this->at('2026-02-01'));

        // A path no supported write can make: across programs.
        DB::table('mlm_genealogy_paths')->insert([
            'tree_type' => 'binary', 'ancestor_id' => $this->members['A']->id, 'descendant_id' => $outsider->id, 'depth' => 1, 'effective_from' => '2026-01-01 00:00:00',
        ]);

        $this->assertSame('10', $this->leg(BinarySide::Left, 'N'));
    }

    public function test_a_side_that_disagrees_with_its_edge_is_refused_not_counted(): void
    {
        $position = $this->link('2026-01-01', 'N', 'A', BinarySide::Left);
        $this->record($this->members['A'], '10', 'a', at: $this->at('2026-02-01'));
        $this->placement()->place($this->members['B'], $this->members['X']);
        DB::table('mlm_binary_placement_positions')->where('id', $position)->update(['placement_edge_id' => DB::table('mlm_placement_edges')->where('member_id', $this->members['B']->id)->value('id')]);

        $this->expectException(CorruptBinaryPlacement::class);
        $this->expectExceptionMessage('a binary parent is always the placement parent');

        $this->leg(BinarySide::Left, 'N');
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function refusedParameters(): array
    {
        $refusals = [
            'no type' => [[], 'requires the "type" parameter'],
            'an invalid type' => [['type' => 'Sales'], 'invalid "type"'],
            'a type that is not a string' => [['type' => 5], 'invalid "type"'],
            'a maximum depth of 0' => [['type' => 'sales', 'max_depth' => 0], 'invalid "max_depth"'],
            'a whole float maximum depth' => [['type' => 'sales', 'max_depth' => 2.0], 'invalid "max_depth"'],
            'a numeric string maximum depth' => [['type' => 'sales', 'max_depth' => '2'], 'invalid "max_depth"'],
            'a boolean maximum depth' => [['type' => 'sales', 'max_depth' => true], 'invalid "max_depth"'],
            'an explicit null maximum depth' => [['type' => 'sales', 'max_depth' => null], 'invalid "max_depth"'],
            'side' => [['type' => 'sales', 'side' => 'left'], 'does not accept "side"'],
            'carry' => [['type' => 'sales', 'carry' => true], 'does not accept "carry"'],
        ];

        $cases = [];

        foreach (['binary.left.volume', 'binary.right.volume'] as $key) {
            foreach ($refusals as $name => [$parameters, $reason]) {
                $cases["{$key}: {$name}"] = [$key, $parameters, $reason];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    #[DataProvider('refusedParameters')]
    public function test_parameters_are_checked_not_ignored(string $key, array $parameters, string $reason): void
    {
        $this->expectException(InvalidMetricParameters::class);
        $this->expectExceptionMessage($reason);

        $this->engine()->resolve($key, new MetricContext($this->members['N'], $parameters));
    }

    public function test_a_qualification_rule_reads_a_leg_through_the_unchanged_engine(): void
    {
        $this->link('2026-01-01', 'N', 'A', BinarySide::Left);
        $this->link('2026-01-01', 'N', 'B', BinarySide::Right);
        $this->record($this->members['A'], '250', 'a', at: $this->at('2026-02-01'));
        $this->record($this->members['B'], '90', 'b', at: $this->at('2026-02-01'));

        $rule = $this->validatedRule(RuleDefinition::all(
            MetricCondition::of('binary.left.volume', ['type' => 'sales'], '>=', '200'),
            MetricCondition::of('binary.right.volume', ['type' => 'sales', 'max_depth' => 3], '>=', '100'),
        ), $this->members['N']->program->plans()->create(['code' => 'MAIN', 'name' => 'Main']));

        $decision = $this->app->make(QualificationEngine::class)->evaluate($rule, new QualificationContext($this->members['N']));

        $this->assertInstanceOf(PlanRule::class, $rule);
        $this->assertFalse($decision->qualified);
        $this->assertSame([['binary.left.volume', '250', true], ['binary.right.volume', '90', false]], array_map(
            static fn (array $node): array => [$node['metric'], $node['value'], $node['passed']],
            $decision->toArray()['trace']['children'],
        ));

        $this->record($this->members['B'], '10', 'b-more', at: $this->at('2026-02-02'));

        $this->assertTrue($this->app->make(QualificationEngine::class)->evaluate($rule, new QualificationContext($this->members['N']))->qualified);
    }

    public function test_a_rank_ladder_reads_a_leg_through_the_unchanged_engines(): void
    {
        $this->link('2026-01-01', 'N', 'A', BinarySide::Left);
        $this->record($this->members['A'], '150', 'a', at: $this->at('2026-02-01'));

        $ladder = $this->validatedLadder([
            'bronze' => [10, RuleDefinition::all(MetricCondition::of('binary.left.volume', ['type' => 'sales'], '>=', '100'))],
            'silver' => [20, RuleDefinition::all(MetricCondition::of('binary.left.volume', ['type' => 'sales', 'max_depth' => 1], '>=', '200'))],
        ], $this->members['N']->program->plans()->create(['code' => 'MAIN', 'name' => 'Main']));

        $this->assertInstanceOf(PlanComponent::class, $ladder);
        $this->assertSame('bronze', $this->app->make(RankEngine::class)->evaluate($ladder, new RankContext($this->members['N']))->selectedRank?->key);
        $this->assertNull($this->app->make(RankEngine::class)->evaluate($ladder, new RankContext($this->members['N'], until: $this->at('2026-02-01')))->selectedRank);
    }

    /**
     * `$child` placed under `$parent` on `$side`, from `$at`.
     *
     * @return string the position's id
     */
    private function link(string $at, string $parent, string $child, BinarySide $side): string
    {
        $this->travelTo($this->at($at));

        return $this->binary()->place($this->members[$child], $this->members[$parent], $side)->id;
    }

    private function leg(
        BinarySide $side,
        string $member,
        ?string $from = null,
        ?string $until = null,
        ?int $maxDepth = null,
        string $type = 'sales',
    ): string {
        return $this->engine()->resolve("binary.{$side->value}.volume", new MetricContext(
            $this->members[$member],
            ['type' => $type, ...($maxDepth === null ? [] : ['max_depth' => $maxDepth])],
            $from === null ? null : $this->at($from),
            $until === null ? null : $this->at($until),
        ))->value();
    }

    private function other(BinarySide $side): BinarySide
    {
        return $side === BinarySide::Left ? BinarySide::Right : BinarySide::Left;
    }

    private function at(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment);
    }

    private function engine(): MetricEngine
    {
        return $this->app->make(MetricEngine::class);
    }
}
