<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Exceptions\CorruptBinaryPlacement;
use PandaBear\Mlm\Models\BinaryPlacementPosition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The binary tree read back: sides, lines and subtrees as they stand and as
 * they stood — from its own positions and paths, never from sponsorship or
 * the generic placement paths.
 */
final class BinaryGenealogyTest extends DatabaseTestCase
{
    use BuildsGenealogies;

    /**
     * @var array<string, Member>
     */
    private array $members;

    protected function setUp(): void
    {
        parent::setUp();

        $this->members = $this->members(Program::factory()->create(), 'P', 'A', 'B', 'C', 'D', 'E');
    }

    public function test_a_parent_and_its_children_are_read_by_side(): void
    {
        $this->side('P', 'A', BinarySide::Left);
        $this->side('P', 'B', BinarySide::Right);
        $this->side('A', 'C', BinarySide::Right);

        $this->assertSame(['A', 'B'], [$this->child('P', BinarySide::Left), $this->child('P', BinarySide::Right)]);
        $this->assertSame([null, 'C'], [$this->child('A', BinarySide::Left), $this->child('A', BinarySide::Right)]);
        $this->assertSame(['P', 'P', 'A'], [$this->parentOf('A'), $this->parentOf('B'), $this->parentOf('C')]);
        $this->assertSame([BinarySide::Left, BinarySide::Right], [$this->binaryTree()->positionOf($this->members['A'])?->side, $this->binaryTree()->positionOf($this->members['C'])?->side]);
        $this->assertSame('C', $this->binaryTree()->positionUnder($this->members['A'], BinarySide::Right)?->member?->member_code);
    }

    public function test_a_side_is_what_was_assigned_not_the_order_of_placement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->binary()->place($this->members['B'], $this->members['P'], BinarySide::Right);
        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));
        $this->binary()->place($this->members['A'], $this->members['P'], BinarySide::Left);

        $this->assertSame(['A', 'B'], [$this->child('P', BinarySide::Left), $this->child('P', BinarySide::Right)]);
        $this->assertSame(['B', 'A'], $this->placement()->directChildren($this->members['P'])->pluck('member_code')->all());
    }

    public function test_a_member_without_a_position_is_a_binary_root_even_when_placed_generically(): void
    {
        $this->placement()->place($this->members['A'], $this->members['P']);

        $this->assertNull($this->parentOf('A'));
        $this->assertNull($this->binaryTree()->positionOf($this->members['A']));
        $this->assertNull($this->parentOf('P'));
        $this->assertNull($this->child('P', BinarySide::Left));
        $this->assertSame([], $this->relatives($this->binaryTree()->ancestors($this->members['A'])));
        $this->assertSame([], $this->relatives($this->binaryTree()->descendants($this->members['P'])));
    }

    public function test_lines_and_subtrees_follow_the_binary_paths_down_to_a_depth(): void
    {
        // P > A left > C left > E right; P > B right > D left.
        $this->side('P', 'A', BinarySide::Left);
        $this->side('P', 'B', BinarySide::Right);
        $this->side('A', 'C', BinarySide::Left);
        $this->side('B', 'D', BinarySide::Left);
        $this->side('C', 'E', BinarySide::Right);

        $this->assertSame(['C@1', 'A@2', 'P@3'], $this->relatives($this->binaryTree()->ancestors($this->members['E'])));
        $this->assertSame(['C@1', 'A@2'], $this->relatives($this->binaryTree()->ancestors($this->members['E'], 2)));
        $this->assertSame(['A@1', 'B@1', 'C@2', 'D@2', 'E@3'], $this->relatives($this->binaryTree()->descendants($this->members['P'])));
        $this->assertSame(['A@1', 'B@1'], $this->relatives($this->binaryTree()->descendants($this->members['P'], 1)));
        $this->assertSame(['C@1', 'E@2'], $this->relatives($this->binaryTree()->descendants($this->members['A'])));
    }

    public function test_the_tree_as_it_stood_leaves_out_what_was_placed_or_adopted_later(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);
        $this->binary()->place($this->members['B'], $this->members['A'], BinarySide::Left);
        $this->travelTo(CarbonImmutable::parse('2026-03-01 00:00:00'));
        $this->binary()->adopt($edge, BinarySide::Right);

        $february = CarbonImmutable::parse('2026-02-15 00:00:00');
        $march = CarbonImmutable::parse('2026-03-01 00:00:00');

        // February: A was placed under P, but not yet on a side.
        $this->assertNull($this->binaryTree()->positionOfAt($this->members['A'], $february));
        $this->assertNull($this->binaryTree()->directParentAt($this->members['A'], $february));
        $this->assertNull($this->binaryTree()->childAt($this->members['P'], BinarySide::Right, $february));
        $this->assertNull($this->binaryTree()->positionUnderAt($this->members['P'], BinarySide::Right, $february));
        $this->assertSame(['A@1'], $this->relatives($this->binaryTree()->ancestorsAt($this->members['B'], $february)));
        $this->assertSame([], $this->relatives($this->binaryTree()->descendantsAt($this->members['P'], $february)));
        $this->assertSame('P', $this->placement()->directParentAt($this->members['A'], $february)?->member_code);

        // From the adoption on, the whole subtree is there.
        $this->assertSame(BinarySide::Right, $this->binaryTree()->positionOfAt($this->members['A'], $march)?->side);
        $this->assertSame('P', $this->binaryTree()->directParentAt($this->members['A'], $march)?->member_code);
        $this->assertSame('A', $this->binaryTree()->childAt($this->members['P'], BinarySide::Right, $march)?->member_code);
        $this->assertSame(['A@1', 'P@2'], $this->relatives($this->binaryTree()->ancestorsAt($this->members['B'], $march)));
        $this->assertSame(['A@1', 'B@2'], $this->relatives($this->binaryTree()->descendantsAt($this->members['P'], $march)));
    }

    public function test_each_genealogy_reads_only_its_own_tree(): void
    {
        // Sponsor: A > B > C. Placement: B > A, B > C, C > D. Binary: only
        // B > C right and C > D left.
        $this->sponsorTree($this->members, ['A' => ['B'], 'B' => ['C']]);
        $this->placement()->place($this->members['A'], $this->members['B']);
        $this->side('B', 'C', BinarySide::Right);
        $this->side('C', 'D', BinarySide::Left);

        $this->assertSame(['B@1', 'C@2'], $this->relatives($this->genealogy()->descendants($this->members['A'])));
        $this->assertSame(['A@1', 'C@1', 'D@2'], $this->relatives($this->placement()->descendants($this->members['B'])));
        $this->assertSame(['C@1', 'D@2'], $this->relatives($this->binaryTree()->descendants($this->members['B'])));
        $this->assertSame([], $this->relatives($this->binaryTree()->descendants($this->members['A'])));
        $this->assertSame('B', $this->genealogy()->directSponsor($this->members['C'])?->member_code);
        $this->assertSame('B', $this->placement()->directParent($this->members['C'])?->member_code);
        $this->assertSame('B', $this->parentOf('C'));
        $this->assertNull($this->parentOf('A'));
        $this->assertSame(
            ['A > A @0', 'A > B @1', 'A > C @2', 'B > B @0', 'B > C @1', 'C > C @0'],
            $this->sponsorState()['paths'],
        );
    }

    public function test_it_reads_the_stored_member_not_the_instances_program(): void
    {
        $this->side('P', 'A', BinarySide::Left);

        // Unsaved changes claiming another program.
        $this->members['P']->program_id = Program::factory()->create()->id;
        $this->members['A']->program_id = $this->members['P']->program_id;

        $this->assertSame('A', $this->child('P', BinarySide::Left));
        $this->assertSame('P', $this->parentOf('A'));
        $this->assertSame(['A@1'], $this->relatives($this->binaryTree()->descendants($this->members['P'])));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function corruptReads(): array
    {
        return ['the position of the child' => ['positionOf'], 'the parent' => ['directParent'], 'the child on the side' => ['child']];
    }

    #[DataProvider('corruptReads')]
    public function test_a_position_that_disagrees_with_its_edge_is_refused_not_read(string $read): void
    {
        $position = $this->side('P', 'A', BinarySide::Left);
        DB::table('mlm_binary_placement_positions')->where('id', $position->id)->update(['parent_id' => $this->members['C']->id]);

        $this->expectException(CorruptBinaryPlacement::class);
        $this->expectExceptionMessage('a binary parent is always the placement parent');

        match ($read) {
            'positionOf' => $this->binaryTree()->positionOf($this->members['A']),
            'directParent' => $this->binaryTree()->directParent($this->members['A']),
            'child' => $this->binaryTree()->child($this->members['C'], BinarySide::Left),
        };
    }

    public function test_a_side_spelled_otherwise_is_refused_not_read(): void
    {
        $position = $this->side('P', 'A', BinarySide::Left);
        DB::table('mlm_binary_placement_positions')->where('id', $position->id)->update(['side' => 'LEFT']);

        $this->expectException(CorruptBinaryPlacement::class);
        $this->expectExceptionMessage("has side 'LEFT'");

        $this->binaryTree()->positionOf($this->members['A']);
    }

    public function test_a_position_across_programs_is_refused_not_read(): void
    {
        $position = $this->side('P', 'A', BinarySide::Left);
        DB::table('mlm_members')->where('id', $this->members['A']->id)->update(['program_id' => Program::factory()->create()->id]);

        $this->expectException(CorruptBinaryPlacement::class);
        $this->expectExceptionMessage("Placement edge [{$position->placement_edge_id}] places member");

        $this->binaryTree()->child($this->members['P'], BinarySide::Left);
    }

    private function side(string $parent, string $child, BinarySide $side): BinaryPlacementPosition
    {
        return $this->binary()->place($this->members[$child], $this->members[$parent], $side);
    }

    private function child(string $parent, BinarySide $side): ?string
    {
        return $this->binaryTree()->child($this->members[$parent], $side)?->member_code;
    }

    private function parentOf(string $member): ?string
    {
        return $this->binaryTree()->directParent($this->members[$member])?->member_code;
    }
}
