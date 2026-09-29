<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PandaBear\Mlm\Binary\BinaryPlacementManager;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Exceptions\CorruptBinaryPlacement;
use PandaBear\Mlm\Exceptions\InvalidBinaryPlacement;
use PandaBear\Mlm\Exceptions\InvalidPlacementAssignment;
use PandaBear\Mlm\Models\BinaryPlacementPosition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The binary overlay is written explicitly: a generic placement edge given
 * its parent's left or right, once — placed that way, or adopted later from
 * then on — while the generic placement keeps any number of children.
 */
final class BinaryPlacementTest extends DatabaseTestCase
{
    use BuildsGenealogies;

    /**
     * @var array<string, Member>
     */
    private array $members;

    protected function setUp(): void
    {
        parent::setUp();

        $this->members = $this->members(Program::factory()->create(), 'P', 'A', 'B', 'C', 'D');
    }

    public function test_a_side_is_left_or_right_spelled_exactly(): void
    {
        $this->assertSame(['left', 'right'], array_map(static fn (BinarySide $side): string => $side->value, BinarySide::cases()));
        $this->assertSame([BinarySide::Left, BinarySide::Right], [BinarySide::parse('left'), BinarySide::parse('right')]);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function misspelledSides(): array
    {
        return [
            'LEFT' => ['LEFT'], 'Right' => ['Right'], 'l' => ['l'], 'r' => ['r'],
            'leading space' => [' left'], 'trailing space' => ['right '], 'trailing newline' => ["left\n"],
            'empty' => [''], 'null' => [null], 'zero' => [0], 'one' => [1], 'true' => [true], 'false' => [false],
        ];
    }

    #[DataProvider('misspelledSides')]
    public function test_anything_else_is_not_a_side(mixed $value): void
    {
        $this->assertNull(BinarySide::parse($value));
    }

    public function test_placing_creates_the_generic_edge_and_its_side_together(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-05 10:00:00'));

        $left = $this->binary()->place($this->members['A'], $this->members['P'], BinarySide::Left);
        $right = $this->binary()->place($this->members['B'], $this->members['P'], BinarySide::Right);

        $this->assertSame([BinarySide::Left, BinarySide::Right], [$left->side, $right->side]);
        $this->assertSame([$this->members['P']->id, $this->members['P']->id], [$left->parent_id, $right->parent_id]);
        $this->assertSame([$this->members['A']->id, $this->members['B']->id], [$left->placementEdge->member_id, $right->placementEdge->member_id]);
        $this->assertTrue($left->assigned_at->equalTo($left->placementEdge->placed_at));
        $this->assertSame(['edges' => ['P > A', 'P > B'], 'paths' => ['A > A @0', 'B > B @0', 'P > A @1', 'P > B @1', 'P > P @0']], $this->placementState());
        $this->assertSame([
            'positions' => ['P > A left @2026-01-05 10:00:00', 'P > B right @2026-01-05 10:00:00'],
            'paths' => ['A > A @0', 'B > B @0', 'P > A @1', 'P > B @1', 'P > P @0'],
        ], $this->binaryState());
    }

    public function test_a_parent_keeps_any_number_of_generic_children_beside_its_two_sides(): void
    {
        $this->binary()->place($this->members['A'], $this->members['P'], BinarySide::Left);
        $this->binary()->place($this->members['B'], $this->members['P'], BinarySide::Right);

        $this->placement()->place($this->members['C'], $this->members['P']);
        $this->placement()->place($this->members['D'], $this->members['P']);

        $this->assertSame(['A', 'B', 'C', 'D'], $this->placement()->directChildren($this->members['P'])->pluck('member_code')->sort()->values()->all());
        $this->assertSame(['A@1', 'B@1'], $this->relatives($this->binaryTree()->descendants($this->members['P'])));
        $this->assertNull($this->binaryTree()->positionOf($this->members['C']));
    }

    /**
     * @return array<string, array{BinarySide}>
     */
    public static function sides(): array
    {
        return ['left' => [BinarySide::Left], 'right' => [BinarySide::Right]];
    }

    #[DataProvider('sides')]
    public function test_a_taken_side_is_refused_and_the_placement_made_for_it_undone(BinarySide $side): void
    {
        $this->binary()->place($this->members['A'], $this->members['P'], $side);
        $before = [$this->placementState(), $this->binaryState(), $this->genealogyState()];

        try {
            $this->binary()->place($this->members['B'], $this->members['P'], $side);
            $this->fail('A second child was placed on one side.');
        } catch (InvalidBinaryPlacement $exception) {
            $this->assertStringContainsString("The {$side->value} of member [{$this->members['P']->id}] is already taken by member [{$this->members['A']->id}]", $exception->getMessage());
        }

        // No generic edge, placement path, position or binary path is left.
        $this->assertSame($before, [$this->placementState(), $this->binaryState(), $this->genealogyState()]);
        $this->assertNull($this->placement()->directParent($this->members['B']));

        // The other side is still free.
        $other = $side === BinarySide::Left ? BinarySide::Right : BinarySide::Left;
        $this->assertSame($other, $this->binary()->place($this->members['B'], $this->members['P'], $other)->side);
    }

    public function test_an_existing_generic_edge_is_adopted_from_the_moment_of_its_adoption(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-10 08:00:00'));
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);
        $generic = $this->placementState();

        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:30:00'));
        $position = $this->binary()->adopt($edge, BinarySide::Right);

        $this->assertSame('2026-03-01 09:30:00', $position->assigned_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-10 08:00:00', $position->placementEdge->placed_at->format('Y-m-d H:i:s'));
        $this->assertSame(['A > A @0' => '2026-03-01 09:30:00', 'P > A @1' => '2026-03-01 09:30:00', 'P > P @0' => '2026-03-01 09:30:00'], $this->pathMoments('binary'));
        $this->assertSame($generic, $this->placementState());
    }

    public function test_an_adoption_never_predates_its_placement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00'));
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);

        // The clock goes back.
        $this->travelTo(CarbonImmutable::parse('2026-03-01 00:00:00'));

        $this->assertSame('2026-06-01 00:00:00', $this->binary()->adopt($edge, BinarySide::Left)->assigned_at->format('Y-m-d H:i:s'));
    }

    public function test_a_placement_takes_its_side_later_only_when_the_binary_tree_already_holds_a_later_moment(): void
    {
        // January: A placed generically; June: adopted, so P is in the binary
        // tree from June.
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);
        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00'));
        $this->binary()->adopt($edge, BinarySide::Left);

        // The clock goes back to April: the generic placement is April's, as
        // the placement tree allows; the side cannot predate P's binary line.
        $this->travelTo(CarbonImmutable::parse('2026-04-01 00:00:00'));
        $position = $this->binary()->place($this->members['B'], $this->members['P'], BinarySide::Right);

        $this->assertSame('2026-04-01 00:00:00', $position->placementEdge->placed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-01 00:00:00', $position->assigned_at->format('Y-m-d H:i:s'));
    }

    public function test_adopting_again_on_the_same_side_returns_the_position(): void
    {
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);
        $first = $this->binary()->adopt($edge, BinarySide::Left);
        $state = $this->binaryState();

        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:00:00'));
        $again = $this->binary()->adopt($edge, BinarySide::Left);

        $this->assertSame($first->id, $again->id);
        $this->assertTrue($first->assigned_at->equalTo($again->assigned_at));
        $this->assertSame($state, $this->binaryState());
    }

    public function test_adopting_on_the_other_side_is_refused_and_moves_nothing(): void
    {
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);
        $this->binary()->adopt($edge, BinarySide::Left);
        $state = $this->binaryState();

        $this->expectException(InvalidBinaryPlacement::class);
        $this->expectExceptionMessage("Placement edge [{$edge->id}] is already binary left, so it cannot be right");

        try {
            $this->binary()->adopt($edge, BinarySide::Right);
        } finally {
            $this->assertSame($state, $this->binaryState());
        }
    }

    public function test_a_placement_made_with_a_side_cannot_be_adopted_onto_the_other(): void
    {
        $position = $this->binary()->place($this->members['A'], $this->members['P'], BinarySide::Right);

        $this->expectException(InvalidBinaryPlacement::class);
        $this->expectExceptionMessage('already binary right, so it cannot be left');

        $this->binary()->adopt($position->placementEdge, BinarySide::Left);
    }

    public function test_adopting_into_a_taken_side_is_refused(): void
    {
        $this->binary()->place($this->members['A'], $this->members['P'], BinarySide::Left);
        $edge = $this->placement()->place($this->members['B'], $this->members['P']);
        $state = $this->binaryState();

        try {
            $this->binary()->adopt($edge, BinarySide::Left);
            $this->fail('A second child was adopted onto one side.');
        } catch (InvalidBinaryPlacement $exception) {
            $this->assertStringContainsString("is already taken by member [{$this->members['A']->id}]", $exception->getMessage());
        }

        $this->assertSame($state, $this->binaryState());
        $this->assertSame(BinarySide::Right, $this->binary()->adopt($edge, BinarySide::Right)->side);
    }

    public function test_an_adopted_edge_brings_its_whole_binary_subtree_from_the_adoption_on(): void
    {
        // January: P > A generic only. February: A > B left and B > C right,
        // binary from the start — A is a binary root with a subtree.
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);
        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));
        $this->binary()->place($this->members['B'], $this->members['A'], BinarySide::Left);
        $this->binary()->place($this->members['C'], $this->members['B'], BinarySide::Right);

        $this->assertSame([], $this->relatives($this->binaryTree()->descendants($this->members['P'])));

        $this->travelTo(CarbonImmutable::parse('2026-04-01 00:00:00'));
        $this->binary()->adopt($edge, BinarySide::Left);

        $this->assertSame(['A@1', 'B@2', 'C@3'], $this->relatives($this->binaryTree()->descendants($this->members['P'])));
        $this->assertSame([
            'A > A @0' => '2026-02-01 00:00:00',
            'A > B @1' => '2026-02-01 00:00:00',
            'A > C @2' => '2026-02-01 00:00:00',
            'B > B @0' => '2026-02-01 00:00:00',
            'B > C @1' => '2026-02-01 00:00:00',
            'C > C @0' => '2026-02-01 00:00:00',
            'P > A @1' => '2026-04-01 00:00:00',
            'P > B @2' => '2026-04-01 00:00:00',
            'P > C @3' => '2026-04-01 00:00:00',
            'P > P @0' => '2026-04-01 00:00:00',
        ], $this->pathMoments('binary'));
    }

    public function test_a_generic_only_edge_below_a_binary_member_stays_out_of_the_binary_tree(): void
    {
        $this->binary()->place($this->members['A'], $this->members['P'], BinarySide::Left);
        $this->placement()->place($this->members['B'], $this->members['A']);

        $this->assertSame(['A@1', 'B@2'], $this->relatives($this->placement()->descendants($this->members['P'])));
        $this->assertSame(['A@1'], $this->relatives($this->binaryTree()->descendants($this->members['P'])));
        $this->assertNull($this->binaryTree()->positionOf($this->members['B']));
        $this->assertNull($this->binaryTree()->directParent($this->members['B']));
        $this->assertSame(['A > A @0', 'P > A @1', 'P > P @0'], $this->binaryState()['paths']);
    }

    public function test_adopting_an_edge_brings_the_childs_binary_subtree_but_never_its_generic_one(): void
    {
        // P > A > B > C, all generic; only B > C is given a side. Then P > A
        // is adopted: A joins P's binary tree, but A > B stays generic, so
        // neither B nor C does.
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);
        $this->placement()->place($this->members['B'], $this->members['A']);
        $this->binary()->place($this->members['C'], $this->members['B'], BinarySide::Right);

        $this->binary()->adopt($edge, BinarySide::Left);

        $this->assertSame(['A@1', 'B@2', 'C@3'], $this->relatives($this->placement()->descendants($this->members['P'])));
        $this->assertSame(['A@1'], $this->relatives($this->binaryTree()->descendants($this->members['P'])));
        $this->assertSame(['B@1'], $this->relatives($this->binaryTree()->ancestors($this->members['C'])));
        $this->assertSame(['A > A @0', 'B > B @0', 'B > C @1', 'C > C @0', 'P > A @1', 'P > P @0'], $this->binaryState()['paths']);
    }

    public function test_placing_across_programs_is_refused_by_the_generic_placement(): void
    {
        $outsider = Member::factory()->create(['member_code' => 'X']);
        $state = [$this->placementState(), $this->binaryState()];

        $this->expectException(InvalidPlacementAssignment::class);
        $this->expectExceptionMessage('placement stays within one program');

        try {
            $this->binary()->place($outsider, $this->members['P'], BinarySide::Left);
        } finally {
            $this->assertSame($state, [$this->placementState(), $this->binaryState()]);
        }
    }

    public function test_an_edge_across_programs_is_refused_on_adoption(): void
    {
        // An edge no supported write can make.
        $outsider = Member::factory()->create(['member_code' => 'X']);
        $id = strtolower((string) Str::ulid());
        DB::table('mlm_placement_edges')->insert(['id' => $id, 'member_id' => $outsider->id, 'parent_id' => $this->members['P']->id, 'placed_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);

        $this->expectException(CorruptBinaryPlacement::class);
        $this->expectExceptionMessage('of another program');

        try {
            $this->binary()->adopt(PlacementEdge::query()->findOrFail($id), BinarySide::Left);
        } finally {
            $this->assertSame(0, BinaryPlacementPosition::query()->count());
        }
    }

    public function test_the_stored_edge_decides_not_the_instance(): void
    {
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);

        // Unsaved changes claiming another parent and another member.
        $edge->parent_id = $this->members['C']->id;
        $edge->member_id = $this->members['D']->id;

        $position = $this->binary()->adopt($edge, BinarySide::Left);

        $this->assertSame($this->members['P']->id, $position->parent_id);
        $this->assertSame('A', $this->binaryTree()->child($this->members['P'], BinarySide::Left)?->member_code);
        $this->assertNull($this->binaryTree()->child($this->members['C'], BinarySide::Left));
    }

    public function test_the_stored_members_decide_not_the_instances(): void
    {
        // An unsaved change claiming another program.
        $this->members['A']->program_id = Program::factory()->create()->id;

        $position = $this->binary()->place($this->members['A'], $this->members['P'], BinarySide::Left);

        $this->assertSame($this->members['P']->program_id, $position->member?->program_id);
    }

    public function test_a_missing_edge_cannot_be_adopted(): void
    {
        $edge = new PlacementEdge;
        $edge->forceFill(['id' => strtolower((string) Str::ulid())]);

        $this->expectException(InvalidBinaryPlacement::class);
        $this->expectExceptionMessage('does not exist; only a stored placement can be adopted');

        $this->binary()->adopt($edge, BinarySide::Left);
    }

    public function test_a_cycle_is_still_refused_by_the_generic_placement(): void
    {
        $this->binary()->place($this->members['A'], $this->members['P'], BinarySide::Left);
        $state = [$this->placementState(), $this->binaryState()];

        $this->expectException(InvalidPlacementAssignment::class);
        $this->expectExceptionMessage('would make a cycle');

        try {
            $this->binary()->place($this->members['P'], $this->members['A'], BinarySide::Right);
        } finally {
            $this->assertSame($state, [$this->placementState(), $this->binaryState()]);
        }
    }

    public function test_a_member_already_placed_is_refused_not_adopted(): void
    {
        $this->placement()->place($this->members['A'], $this->members['P']);

        $this->expectException(InvalidPlacementAssignment::class);
        $this->expectExceptionMessage('is already placed under member');

        try {
            $this->binary()->place($this->members['A'], $this->members['P'], BinarySide::Left);
        } finally {
            $this->assertSame(0, BinaryPlacementPosition::query()->count());
        }
    }

    public function test_binary_placement_neither_reads_nor_writes_sponsorship(): void
    {
        // Q sponsors A, but A is placed under P.
        $this->members['Q'] = Member::factory()->for($this->members['P']->program)->create(['member_code' => 'Q']);
        $this->genealogy()->assignSponsor($this->members['A'], $this->members['Q']);
        $sponsorship = $this->sponsorState();

        $this->binary()->place($this->members['A'], $this->members['P'], BinarySide::Left);
        $this->binary()->place($this->members['B'], $this->members['P'], BinarySide::Right);

        $this->assertSame($sponsorship, $this->sponsorState());
        $this->assertSame('P', $this->binaryTree()->directParent($this->members['A'])?->member_code);

        // A later sponsorship moves no side and no parent.
        $binary = $this->binaryState();
        $this->genealogy()->assignSponsor($this->members['B'], $this->members['A']);

        $this->assertSame($binary, $this->binaryState());
        $this->assertSame('B', $this->binaryTree()->child($this->members['P'], BinarySide::Right)?->member_code);
    }

    public function test_a_position_that_disagrees_with_its_edge_is_refused_not_repaired(): void
    {
        $position = $this->binary()->place($this->members['A'], $this->members['P'], BinarySide::Left);
        DB::table('mlm_binary_placement_positions')->where('id', $position->id)->update(['parent_id' => $this->members['C']->id]);

        $this->expectException(CorruptBinaryPlacement::class);
        $this->expectExceptionMessage("names parent [{$this->members['C']->id}], but its placement edge [{$position->placement_edge_id}] is under [{$this->members['P']->id}]");

        $this->binary()->adopt($position->placementEdge, BinarySide::Left);
    }

    public function test_positions_are_read_only_through_eloquent_and_related_to_their_edge(): void
    {
        $position = $this->binary()->place($this->members['A'], $this->members['P'], BinarySide::Left);

        $this->assertTrue($position->placementEdge->is(PlacementEdge::query()->where('member_id', $this->members['A']->id)->sole()));
        $this->assertTrue($position->parent->is($this->members['P']));
        $this->assertTrue($position->member?->is($this->members['A']));

        foreach ([
            'create' => static fn () => BinaryPlacementPosition::query()->forceCreate(['side' => 'right']),
            'update' => static fn () => $position->forceFill(['side' => 'right'])->save(),
            'delete' => static fn () => $position->delete(),
        ] as $write => $attempt) {
            try {
                $attempt();
                $this->fail("A position could be written through Eloquent: {$write}.");
            } catch (InvalidBinaryPlacement $exception) {
                $this->assertStringContainsString('written only by PandaBear\Mlm\Binary\BinaryPlacementManager', $exception->getMessage());
            }
        }

        $this->assertSame(['P > A left @'.$position->assigned_at->format('Y-m-d H:i:s')], $this->binaryState()['positions']);
    }

    public function test_the_manager_offers_no_move_no_removal_and_no_automatic_placement(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(BinaryPlacementManager::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $this->assertEqualsCanonicalizing(['__construct', 'place', 'adopt'], $methods);
    }
}
