<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Exceptions\CorruptMatrixPlacement;
use PandaBear\Mlm\Exceptions\InvalidMatrixNetwork;
use PandaBear\Mlm\Exceptions\InvalidMatrixPlacement;
use PandaBear\Mlm\Exceptions\InvalidPlacementAssignment;
use PandaBear\Mlm\Matrix\MatrixNetworkManager;
use PandaBear\Mlm\Matrix\MatrixPlacementManager;
use PandaBear\Mlm\Models\MatrixNetwork;
use PandaBear\Mlm\Models\MatrixPlacementPosition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The matrix overlay is written explicitly (ADR-027): a program's network
 * gives every matrix parent numbered slots up to a width set once, and a
 * generic placement edge is given one of its parent's slots, once — placed
 * that way, or adopted later from then on — while the generic placement
 * keeps any number of children.
 */
final class MatrixPlacementTest extends DatabaseTestCase
{
    use BuildsGenealogies;

    private Program $program;

    /**
     * @var array<string, Member>
     */
    private array $members;

    protected function setUp(): void
    {
        parent::setUp();

        $this->program = Program::factory()->create();
        $this->members = $this->members($this->program, 'P', 'A', 'B', 'C', 'D', 'X');
    }

    public function test_a_program_is_configured_once_and_again_with_the_same_width(): void
    {
        $network = $this->matrixNetworks()->configure($this->program, 3);

        $this->assertSame([$this->program->id, 3], [$network->program_id, $network->width]);
        $this->assertTrue($network->is($this->matrixNetworks()->configure($this->program, 3)));
        $this->assertTrue($network->program->is($this->program));
        $this->assertSame(1, MatrixNetwork::query()->count());
        $this->assertTrue($network->is($this->matrixTree()->network($this->program)));
        $this->assertTrue($network->is($this->matrixTree()->network($this->members['A'])));
    }

    public function test_a_width_never_changes(): void
    {
        $network = $this->matrixNetworks()->configure($this->program, 3);

        try {
            $this->matrixNetworks()->configure($this->program, 4);
            $this->fail('A matrix width changed.');
        } catch (InvalidMatrixNetwork $exception) {
            $this->assertStringContainsString('already has a matrix network of width 3; it cannot become 4', $exception->getMessage());
        }

        $this->assertSame(3, MatrixNetwork::query()->sole()->width);
        $this->assertTrue($network->is(MatrixNetwork::query()->sole()));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidWidths(): array
    {
        return [
            'zero' => [0], 'negative' => [-1], 'beyond 100' => [101], 'a float' => [3.0],
            'a numeric string' => ['3'], 'true' => [true], 'null' => [null],
        ];
    }

    #[DataProvider('invalidWidths')]
    public function test_a_width_is_a_whole_number_from_1_to_100_never_cast(mixed $width): void
    {
        try {
            $this->matrixNetworks()->configure($this->program, $width);
            $this->fail('An invalid width was accepted.');
        } catch (InvalidMatrixNetwork $exception) {
            $this->assertStringContainsString('A matrix width is a PHP integer from 1 to 100', $exception->getMessage());
        }

        $this->assertSame(0, MatrixNetwork::query()->count());
        $this->assertSame([1, 100], [$this->matrixNetworks()->configure($this->program, 1)->width, $this->matrixNetworks()->configure(Program::factory()->create(), 100)->width]);
    }

    public function test_placing_creates_the_generic_edge_and_its_slot_together(): void
    {
        $this->matrixNetworks()->configure($this->program, 3);
        $this->travelTo(CarbonImmutable::parse('2026-01-05 10:00:00'));

        $first = $this->matrix()->place($this->members['A'], $this->members['P'], 1);
        $third = $this->matrix()->place($this->members['B'], $this->members['P'], 3);

        $this->assertSame([1, 3], [$first->slot, $third->slot]);
        $this->assertSame([$this->members['A']->id, $this->members['B']->id], [$first->member?->id, $third->member?->id]);
        $this->assertTrue($first->network->is(MatrixNetwork::query()->sole()));
        $this->assertTrue($first->assigned_at->equalTo($first->placementEdge->placed_at));
        $this->assertSame(['edges' => ['P > A', 'P > B'], 'paths' => ['A > A @0', 'B > B @0', 'P > A @1', 'P > B @1', 'P > P @0']], $this->placementState());
        $this->assertSame([
            'positions' => ['P > A #1 @2026-01-05 10:00:00', 'P > B #3 @2026-01-05 10:00:00'],
            'paths' => ['A > A @0', 'B > B @0', 'P > A @1', 'P > B @1', 'P > P @0'],
        ], $this->matrixState());
        // The generic placement table knows nothing of slots or widths.
        $this->assertSame(['id', 'member_id', 'parent_id', 'placed_at', 'created_at', 'updated_at'], Schema::getColumnListing('mlm_placement_edges'));
    }

    public function test_a_parent_keeps_any_number_of_generic_children_beside_its_full_matrix(): void
    {
        $this->matrixNetworks()->configure($this->program, 2);
        $this->matrix()->place($this->members['A'], $this->members['P'], 1);
        $this->matrix()->place($this->members['B'], $this->members['P'], 2);

        $this->placement()->place($this->members['C'], $this->members['P']);
        $this->placement()->place($this->members['D'], $this->members['P']);

        $this->assertSame(['A', 'B', 'C', 'D'], $this->placement()->directChildren($this->members['P'])->pluck('member_code')->sort()->values()->all());
        $this->assertSame(['A@1', 'B@1'], $this->relatives($this->matrixTree()->descendants($this->members['P'])));
        $this->assertNull($this->matrixTree()->positionOf($this->members['C']));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidSlots(): array
    {
        return [
            'zero' => [0, 'A matrix slot is a PHP integer of 1 or more'],
            'negative' => [-1, 'A matrix slot is a PHP integer of 1 or more'],
            'beyond the width' => [4, 'Matrix slot 4 does not exist: the matrix is 3 wide'],
            'a numeric string' => ['1', 'A matrix slot is a PHP integer of 1 or more'],
            'a float' => [1.0, 'A matrix slot is a PHP integer of 1 or more'],
            'true' => [true, 'A matrix slot is a PHP integer of 1 or more'],
            'null' => [null, 'A matrix slot is a PHP integer of 1 or more'],
        ];
    }

    #[DataProvider('invalidSlots')]
    public function test_a_slot_is_one_of_the_networks_never_cast(mixed $slot, string $reason): void
    {
        $this->matrixNetworks()->configure($this->program, 3);
        $edge = $this->placement()->place($this->members['B'], $this->members['P']);
        $before = [$this->placementState(), $this->matrixState()];

        foreach ([fn () => $this->matrix()->place($this->members['A'], $this->members['P'], $slot), fn () => $this->matrix()->adopt($edge, $slot)] as $attempt) {
            try {
                $attempt();
                $this->fail('An invalid slot was accepted.');
            } catch (InvalidMatrixPlacement $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
        }

        // Nothing is left of the placement made for it.
        $this->assertSame($before, [$this->placementState(), $this->matrixState()]);
        $this->assertNull($this->placement()->directParent($this->members['A']));
    }

    public function test_a_taken_slot_is_refused_and_the_placement_made_for_it_undone(): void
    {
        $this->matrixNetworks()->configure($this->program, 3);
        $this->matrix()->place($this->members['A'], $this->members['P'], 2);
        $before = [$this->placementState(), $this->matrixState(), $this->genealogyState()];

        try {
            $this->matrix()->place($this->members['B'], $this->members['P'], 2);
            $this->fail('A second member took one slot.');
        } catch (InvalidMatrixPlacement $exception) {
            $this->assertStringContainsString("Matrix slot 2 of member [{$this->members['P']->id}] is already taken by member [{$this->members['A']->id}]", $exception->getMessage());
        }

        // No generic edge, placement path, position or matrix path is left.
        $this->assertSame($before, [$this->placementState(), $this->matrixState(), $this->genealogyState()]);
        $this->assertNull($this->placement()->directParent($this->members['B']));
        $this->assertSame(1, $this->matrix()->place($this->members['B'], $this->members['P'], 1)->slot);
    }

    public function test_a_program_without_a_network_has_no_matrix_and_keeps_no_placement_made_for_one(): void
    {
        $state = [$this->placementState(), $this->matrixState()];

        try {
            $this->matrix()->place($this->members['A'], $this->members['P'], 1);
            $this->fail('A matrix placement was made without a network.');
        } catch (InvalidMatrixPlacement $exception) {
            $this->assertStringContainsString("Program [{$this->program->id}] has no matrix network", $exception->getMessage());
        }

        $this->assertSame($state, [$this->placementState(), $this->matrixState()]);
        $this->assertNull($this->matrixTree()->network($this->program));
    }

    public function test_an_existing_generic_edge_is_adopted_from_the_moment_of_its_adoption(): void
    {
        $this->matrixNetworks()->configure($this->program, 3);
        $this->travelTo(CarbonImmutable::parse('2026-01-10 08:00:00'));
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);
        $generic = $this->placementState();

        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:30:00'));
        $position = $this->matrix()->adopt($edge, 3);

        $this->assertSame(['2026-03-01 09:30:00', '2026-01-10 08:00:00', 3], [$position->assigned_at->format('Y-m-d H:i:s'), $position->placementEdge->placed_at->format('Y-m-d H:i:s'), $position->slot]);
        $this->assertSame(['A > A @0' => '2026-03-01 09:30:00', 'P > A @1' => '2026-03-01 09:30:00', 'P > P @0' => '2026-03-01 09:30:00'], $this->pathMoments('matrix'));
        $this->assertSame($generic, $this->placementState());
        $this->assertNull($this->matrixTree()->directParentAt($this->members['A'], CarbonImmutable::parse('2026-02-01')));
    }

    public function test_an_adoption_never_predates_its_placement(): void
    {
        $this->matrixNetworks()->configure($this->program, 3);
        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00'));
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);

        // The clock goes back.
        $this->travelTo(CarbonImmutable::parse('2026-03-01 00:00:00'));

        $this->assertSame('2026-06-01 00:00:00', $this->matrix()->adopt($edge, 1)->assigned_at->format('Y-m-d H:i:s'));
    }

    public function test_a_placement_takes_its_slot_later_only_when_the_matrix_already_holds_a_later_moment(): void
    {
        // January: A placed generically; June: adopted, so P is in the matrix
        // from June.
        $this->matrixNetworks()->configure($this->program, 3);
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);
        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00'));
        $this->matrix()->adopt($edge, 1);

        // The clock goes back to April: the generic placement is April's; the
        // slot cannot predate P's matrix line.
        $this->travelTo(CarbonImmutable::parse('2026-04-01 00:00:00'));
        $position = $this->matrix()->place($this->members['B'], $this->members['P'], 2);

        $this->assertSame(['2026-04-01 00:00:00', '2026-06-01 00:00:00'], [$position->placementEdge->placed_at->format('Y-m-d H:i:s'), $position->assigned_at->format('Y-m-d H:i:s')]);
    }

    public function test_adopting_again_into_the_same_slot_returns_the_position_and_another_slot_is_refused(): void
    {
        $this->matrixNetworks()->configure($this->program, 3);
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);
        $position = $this->matrix()->adopt($edge, 2);
        $state = [$this->matrixState(), $this->genealogyState()];

        $this->assertTrue($position->is($this->matrix()->adopt($edge, 2)));

        try {
            $this->matrix()->adopt($edge, 3);
            $this->fail('A matrix slot moved.');
        } catch (InvalidMatrixPlacement $exception) {
            $this->assertStringContainsString('is already in matrix slot 2, so it cannot take slot 3', $exception->getMessage());
        }

        $this->assertSame($state, [$this->matrixState(), $this->genealogyState()]);
        // A placement made with a slot is the same: it keeps it.
        $placed = $this->matrix()->place($this->members['B'], $this->members['P'], 1);
        $this->assertTrue($placed->is($this->matrix()->adopt($placed->placementEdge, 1)));
    }

    public function test_adopting_into_a_taken_slot_is_refused(): void
    {
        $this->matrixNetworks()->configure($this->program, 3);
        $this->matrix()->place($this->members['A'], $this->members['P'], 1);
        $edge = $this->placement()->place($this->members['B'], $this->members['P']);

        $this->expectException(InvalidMatrixPlacement::class);
        $this->expectExceptionMessage('is already taken by member');

        $this->matrix()->adopt($edge, 1);
    }

    public function test_an_adopted_edge_brings_its_whole_matrix_subtree_from_the_adoption_on(): void
    {
        // January: P > A generic only. February: A > B and B > C in the
        // matrix from the start — A is a matrix root with a subtree.
        $this->matrixNetworks()->configure($this->program, 3);
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);
        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));
        $this->matrix()->place($this->members['B'], $this->members['A'], 1);
        $this->matrix()->place($this->members['C'], $this->members['B'], 3);

        $this->assertSame([], $this->relatives($this->matrixTree()->descendants($this->members['P'])));

        $this->travelTo(CarbonImmutable::parse('2026-04-01 00:00:00'));
        $this->matrix()->adopt($edge, 2);

        $this->assertSame(['A@1', 'B@2', 'C@3'], $this->relatives($this->matrixTree()->descendants($this->members['P'])));
        // The subtree's own paths keep their moments; only the new ones are April's.
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
        ], $this->pathMoments('matrix'));
        $this->assertSame(['B@1', 'A@2'], $this->relatives($this->matrixTree()->ancestorsAt($this->members['C'], CarbonImmutable::parse('2026-03-01'))));
    }

    public function test_a_generic_only_edge_below_a_matrix_member_stays_out_of_the_matrix(): void
    {
        $this->matrixNetworks()->configure($this->program, 3);
        $this->matrix()->place($this->members['A'], $this->members['P'], 1);
        $this->placement()->place($this->members['X'], $this->members['A']);

        $this->assertSame(['A@1', 'X@2'], $this->relatives($this->placement()->descendants($this->members['P'])));
        $this->assertSame(['A@1'], $this->relatives($this->matrixTree()->descendants($this->members['P'])));
        $this->assertNull($this->matrixTree()->positionOf($this->members['X']));
        $this->assertSame(['A > A @0', 'P > A @1', 'P > P @0'], $this->matrixState()['paths']);
    }

    public function test_the_matrix_binary_and_sponsor_trees_are_independent(): void
    {
        // Q sponsors A; A is P's binary left child and P's matrix slot 1.
        $this->matrixNetworks()->configure($this->program, 3);
        $this->members['Q'] = Member::factory()->for($this->program)->create(['member_code' => 'Q']);
        $this->genealogy()->assignSponsor($this->members['A'], $this->members['Q']);
        $edge = $this->binary()->place($this->members['A'], $this->members['P'], BinarySide::Left)->placementEdge;
        $sponsorship = $this->sponsorState();
        $binary = $this->binaryState();

        $this->matrix()->adopt($edge, 1);
        $this->matrix()->place($this->members['B'], $this->members['A'], 3);

        $this->assertSame([$sponsorship, $binary], [$this->sponsorState(), $this->binaryState()]);
        $this->assertSame(['A@1', 'B@2'], $this->relatives($this->matrixTree()->descendants($this->members['P'])));
        $this->assertSame(['A@1'], $this->relatives($this->binaryTree()->descendants($this->members['P'])));
        $this->assertSame([], $this->relatives($this->matrixTree()->descendants($this->members['Q'])));

        // A later sponsorship or binary placement moves nothing in the matrix.
        $matrix = $this->matrixState();
        $this->genealogy()->assignSponsor($this->members['B'], $this->members['Q']);
        $this->binary()->place($this->members['C'], $this->members['A'], BinarySide::Right);

        $this->assertSame($matrix, $this->matrixState());
    }

    public function test_placing_across_programs_is_refused_by_the_generic_placement(): void
    {
        $this->matrixNetworks()->configure($this->program, 3);
        $outsider = Member::factory()->create(['member_code' => 'Z']);
        $state = [$this->placementState(), $this->matrixState()];

        try {
            $this->matrix()->place($outsider, $this->members['P'], 1);
            $this->fail('A matrix placement crossed programs.');
        } catch (InvalidPlacementAssignment $exception) {
            $this->assertStringContainsString('placement stays within one program', $exception->getMessage());
        }

        $this->assertSame($state, [$this->placementState(), $this->matrixState()]);
    }

    public function test_the_stored_edge_and_members_decide_not_the_instances(): void
    {
        $this->matrixNetworks()->configure($this->program, 3);
        $edge = $this->placement()->place($this->members['A'], $this->members['P']);
        $edge->parent_id = $this->members['B']->id;

        $this->assertSame($this->members['P']->id, $this->matrix()->adopt($edge, 1)->parent_id);

        $this->expectException(InvalidMatrixPlacement::class);
        $this->expectExceptionMessage('does not exist; only a stored placement can be adopted');

        $this->matrix()->adopt((new PlacementEdge)->forceFill(['id' => '01missing0000000000000000']), 1);
    }

    public function test_a_position_that_disagrees_with_its_edge_is_refused_not_repaired(): void
    {
        $this->matrixNetworks()->configure($this->program, 3);
        $position = $this->matrix()->place($this->members['A'], $this->members['P'], 1);
        DB::table('mlm_matrix_placement_positions')->where('id', $position->id)->update(['parent_id' => $this->members['C']->id]);

        $this->expectException(CorruptMatrixPlacement::class);
        $this->expectExceptionMessage("names parent [{$this->members['C']->id}], but its placement edge [{$position->placement_edge_id}] is under [{$this->members['P']->id}]");

        $this->matrix()->adopt($position->placementEdge, 1);
    }

    public function test_networks_and_positions_are_read_only_through_eloquent(): void
    {
        $network = $this->matrixNetworks()->configure($this->program, 3);
        $position = $this->matrix()->place($this->members['A'], $this->members['P'], 1);

        $this->assertTrue($position->placementEdge->is(PlacementEdge::query()->where('member_id', $this->members['A']->id)->sole()));
        $this->assertTrue($position->parent->is($this->members['P']));
        $this->assertTrue($position->network->is($network));
        $this->assertSame([$position->id], $network->positions()->pluck('id')->all());

        foreach ([
            'create a position' => [static fn () => MatrixPlacementPosition::query()->forceCreate(['slot' => 2]), InvalidMatrixPlacement::class],
            'move a position' => [static fn () => $position->forceFill(['slot' => 2])->save(), InvalidMatrixPlacement::class],
            'remove a position' => [static fn () => $position->delete(), InvalidMatrixPlacement::class],
            'create a network' => [static fn () => MatrixNetwork::query()->forceCreate(['width' => 2]), InvalidMatrixNetwork::class],
            'widen a network' => [static fn () => $network->forceFill(['width' => 4])->save(), InvalidMatrixNetwork::class],
            'remove a network' => [static fn () => $network->delete(), InvalidMatrixNetwork::class],
        ] as $write => [$attempt, $refusal]) {
            try {
                $attempt();
                $this->fail("A matrix row could be written through Eloquent: {$write}.");
            } catch (InvalidMatrixPlacement|InvalidMatrixNetwork $exception) {
                $this->assertInstanceOf($refusal, $exception, $write);
            }
        }

        $this->assertSame(['P > A #1 @'.$position->assigned_at->format('Y-m-d H:i:s')], $this->matrixState()['positions']);
        $this->assertSame(3, MatrixNetwork::query()->sole()->width);
    }

    public function test_the_services_offer_no_move_no_removal_no_reconfiguration_and_no_automatic_placement(): void
    {
        $methods = static fn (string $class): array => array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $this->assertEqualsCanonicalizing(['__construct', 'place', 'adopt'], $methods(MatrixPlacementManager::class));
        $this->assertEqualsCanonicalizing(['configure'], $methods(MatrixNetworkManager::class));
    }
}
