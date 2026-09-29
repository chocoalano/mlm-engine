<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\CorruptMatrixPlacement;
use PandaBear\Mlm\Exceptions\InvalidMatrixPlacement;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The matrix is read through its own paths and positions (ADR-027): by
 * slot, line and subtree, as it stands or as it stood — and a stored
 * position that disagrees with its edge, its network or its width is
 * refused, not read.
 */
final class MatrixGenealogyTest extends DatabaseTestCase
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

        // January: P > A #1, P > B #3, A > C #2. March: C > D #1.
        $this->program = Program::factory()->create();
        $this->members = $this->members($this->program, 'P', 'A', 'B', 'C', 'D', 'G');
        $this->matrixNetworks()->configure($this->program, 3);
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->matrix()->place($this->members['A'], $this->members['P'], 1);
        $this->matrix()->place($this->members['B'], $this->members['P'], 3);
        $this->matrix()->place($this->members['C'], $this->members['A'], 2);
        $this->travelTo(CarbonImmutable::parse('2026-03-01 00:00:00'));
        $this->matrix()->place($this->members['D'], $this->members['C'], 1);
        $this->travelBack();
    }

    public function test_a_parent_and_its_children_are_read_by_slot(): void
    {
        $this->assertSame(['A', null, 'B'], array_map(fn (int $slot): ?string => $this->matrixTree()->child($this->members['P'], $slot)?->member_code, [1, 2, 3]));
        $this->assertSame('P', $this->matrixTree()->directParent($this->members['A'])?->member_code);

        $position = $this->matrixTree()->positionOf($this->members['C']);
        $this->assertSame([2, 'A', 'C'], [$position?->slot, $position?->parent->member_code, $position?->member?->member_code]);
    }

    public function test_a_member_without_a_position_is_a_matrix_root_even_when_placed_generically(): void
    {
        $this->placement()->place($this->members['G'], $this->members['P']);

        $this->assertNull($this->matrixTree()->positionOf($this->members['G']));
        $this->assertNull($this->matrixTree()->directParent($this->members['G']));
        $this->assertNull($this->matrixTree()->positionOf($this->members['P']));
        $this->assertSame([], $this->relatives($this->matrixTree()->ancestors($this->members['G'])));
    }

    public function test_lines_and_subtrees_follow_the_matrix_paths_to_any_depth(): void
    {
        $this->assertSame(['C@1', 'A@2', 'P@3'], $this->relatives($this->matrixTree()->ancestors($this->members['D'])));
        $this->assertSame(['C@1', 'A@2'], $this->relatives($this->matrixTree()->ancestors($this->members['D'], 2)));
        $this->assertSame(['A@1', 'B@1', 'C@2', 'D@3'], $this->relatives($this->matrixTree()->descendants($this->members['P'])));
        $this->assertSame(['A@1', 'B@1'], $this->relatives($this->matrixTree()->descendants($this->members['P'], 1)));
    }

    public function test_the_matrix_as_it_stood_leaves_out_what_was_placed_or_adopted_later(): void
    {
        $february = CarbonImmutable::parse('2026-02-01 00:00:00');

        $this->assertSame(['A@1', 'B@1', 'C@2'], $this->relatives($this->matrixTree()->descendantsAt($this->members['P'], $february)));
        $this->assertSame([], $this->relatives($this->matrixTree()->ancestorsAt($this->members['D'], $february)));
        $this->assertNull($this->matrixTree()->positionOfAt($this->members['D'], $february));
        $this->assertNull($this->matrixTree()->directParentAt($this->members['D'], $february));
        $this->assertNull($this->matrixTree()->childAt($this->members['C'], 1, $february));
        $this->assertSame('D', $this->matrixTree()->childAt($this->members['C'], 1, CarbonImmutable::parse('2026-03-01 00:00:00'))?->member_code);
        $this->assertSame('C', $this->matrixTree()->directParentAt($this->members['D'], CarbonImmutable::parse('2026-03-01 00:00:00'))?->member_code);
    }

    public function test_the_matrix_reads_only_its_own_tree(): void
    {
        // G is placed generically under D; generic and matrix trees now differ.
        $this->placement()->place($this->members['G'], $this->members['D']);

        $this->assertSame(['A@1', 'B@1', 'C@2', 'D@3', 'G@4'], $this->relatives($this->placement()->descendants($this->members['P'])));
        $this->assertSame(['A@1', 'B@1', 'C@2', 'D@3'], $this->relatives($this->matrixTree()->descendants($this->members['P'])));
        $this->assertSame([], $this->relatives($this->binaryTree()->descendants($this->members['P'])));
        $this->assertSame([], $this->relatives($this->genealogy()->descendants($this->members['P'])));
    }

    public function test_it_reads_the_stored_member_not_the_instances_program(): void
    {
        $other = Program::factory()->create();
        $this->members['A']->program_id = $other->id;

        $this->assertSame('P', $this->matrixTree()->directParent($this->members['A'])?->member_code);
        $this->assertSame(['C@1'], $this->relatives($this->matrixTree()->descendants($this->members['A'], 1)));
        $this->assertSame(3, $this->matrixTree()->network($this->members['A'])?->width);
        $this->assertNull($this->matrixTree()->network($other));
    }

    public function test_a_slot_asked_for_is_a_whole_number_of_1_or_more(): void
    {
        $this->assertNull($this->matrixTree()->child($this->members['B'], 2));

        foreach ([0, '1', 1.0, null] as $slot) {
            try {
                $this->matrixTree()->child($this->members['P'], $slot);
                $this->fail('An invalid slot was read.');
            } catch (InvalidMatrixPlacement $exception) {
                $this->assertStringContainsString('A matrix slot is a PHP integer of 1 or more', $exception->getMessage());
            }
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function corruptions(): array
    {
        return [
            'a parent other than its edge’s' => ['parent', 'names parent'],
            'a slot beyond the width' => ['slot', 'its network\'s slots are 1 to 3'],
            'another program’s network' => ['network', 'not its program\'s network'],
        ];
    }

    #[DataProvider('corruptions')]
    public function test_a_position_that_disagrees_with_its_edge_network_or_width_is_refused_not_read(string $corruption, string $reason): void
    {
        $position = $this->matrixTree()->positionOf($this->members['B']);
        $this->assertNotNull($position);

        DB::table('mlm_matrix_placement_positions')->where('id', $position->id)->update(match ($corruption) {
            'parent' => ['parent_id' => $this->members['C']->id],
            'slot' => ['slot' => 4],
            'network' => ['matrix_network_id' => $this->matrixNetworks()->configure(Program::factory()->create(), 3)->id],
        });

        foreach ([fn () => $this->matrixTree()->positionOf($this->members['B']), fn () => $this->matrixTree()->directParent($this->members['B'])] as $read) {
            try {
                $read();
                $this->fail('A corrupt matrix position was read.');
            } catch (CorruptMatrixPlacement $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
        }
    }

    public function test_a_position_across_programs_is_refused_not_read(): void
    {
        $outsider = Member::factory()->create(['member_code' => 'Z']);
        $edge = $this->matrixTree()->positionOf($this->members['B'])?->placement_edge_id;
        DB::table('mlm_placement_edges')->where('id', $edge)->update(['member_id' => $outsider->id]);

        $this->expectException(CorruptMatrixPlacement::class);
        $this->expectExceptionMessage('of another program; the matrix stays within one program');

        $this->matrixTree()->child($this->members['P'], 3);
    }
}
