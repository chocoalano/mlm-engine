<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every genealogy path records when it took effect: the moment of the edge
 * that completed it. Self paths record the member's first edge in the tree.
 * The same rules hold for the sponsor and the placement tree.
 */
final class GenealogyPathEffectivityTest extends DatabaseTestCase
{
    use BuildsGenealogies;

    /**
     * @return array<string, array{'sponsor'|'placement'}>
     */
    public static function trees(): array
    {
        return ['sponsor tree' => ['sponsor'], 'placement tree' => ['placement']];
    }

    #[DataProvider('trees')]
    public function test_a_direct_path_and_both_self_paths_take_effect_with_the_edge(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B');

        $this->link($tree, $members, '2026-01-01 10:00:00', 'A', 'B');

        $this->assertSame([
            'A > A @0' => '2026-01-01 10:00:00',
            'A > B @1' => '2026-01-01 10:00:00',
            'B > B @0' => '2026-01-01 10:00:00',
        ], $this->pathMoments($tree));
    }

    #[DataProvider('trees')]
    public function test_an_indirect_path_takes_effect_when_its_chain_is_complete(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C');

        $this->link($tree, $members, '2026-01-01 00:00:00', 'A', 'B');
        $this->link($tree, $members, '2026-02-01 00:00:00', 'B', 'C');

        $this->assertSame([
            'A > A @0' => '2026-01-01 00:00:00',
            'A > B @1' => '2026-01-01 00:00:00',
            'A > C @2' => '2026-02-01 00:00:00',
            'B > B @0' => '2026-01-01 00:00:00',
            'B > C @1' => '2026-02-01 00:00:00',
            'C > C @0' => '2026-02-01 00:00:00',
        ], $this->pathMoments($tree));
    }

    #[DataProvider('trees')]
    public function test_an_attached_subtree_takes_effect_when_it_is_joined(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C', 'D');

        // B -> C -> D grows on its own; A joins it from above in March.
        $this->link($tree, $members, '2026-01-01 00:00:00', 'B', 'C');
        $this->link($tree, $members, '2026-02-01 00:00:00', 'C', 'D');
        $this->link($tree, $members, '2026-03-01 00:00:00', 'A', 'B');

        $this->assertSame([
            'A > A @0' => '2026-03-01 00:00:00',
            'A > B @1' => '2026-03-01 00:00:00',
            'A > C @2' => '2026-03-01 00:00:00',
            'A > D @3' => '2026-03-01 00:00:00',
            'B > B @0' => '2026-01-01 00:00:00',
            'B > C @1' => '2026-01-01 00:00:00',
            'B > D @2' => '2026-02-01 00:00:00',
            'C > C @0' => '2026-01-01 00:00:00',
            'C > D @1' => '2026-02-01 00:00:00',
            'D > D @0' => '2026-02-01 00:00:00',
        ], $this->pathMoments($tree));
    }

    #[DataProvider('trees')]
    public function test_joined_paths_do_not_inherit_the_older_subtrees_moment(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C');

        $this->link($tree, $members, '2026-01-01 00:00:00', 'B', 'C');
        $this->link($tree, $members, '2026-03-01 00:00:00', 'A', 'B');

        $moments = $this->pathMoments($tree);

        $this->assertSame('2026-01-01 00:00:00', $moments['B > C @1']);
        $this->assertSame('2026-03-01 00:00:00', $moments['A > B @1']);
        $this->assertSame('2026-03-01 00:00:00', $moments['A > C @2']);
    }

    #[DataProvider('trees')]
    public function test_a_self_path_keeps_the_moment_of_its_members_first_edge(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C', 'D');

        $this->link($tree, $members, '2026-01-01 00:00:00', 'A', 'B');
        $this->link($tree, $members, '2026-03-01 00:00:00', 'A', 'C');
        // A, a root with a subtree, joins D's tree in April.
        $this->link($tree, $members, '2026-04-01 00:00:00', 'D', 'A');

        $moments = $this->pathMoments($tree);

        $this->assertSame('2026-01-01 00:00:00', $moments['A > A @0']);
        $this->assertSame('2026-01-01 00:00:00', $moments['B > B @0']);
        $this->assertSame('2026-03-01 00:00:00', $moments['C > C @0']);
        $this->assertSame('2026-04-01 00:00:00', $moments['D > D @0']);
        $this->assertSame('2026-04-01 00:00:00', $moments['D > B @2']);
        $this->assertSame('2026-04-01 00:00:00', $moments['D > C @2']);
    }

    #[DataProvider('trees')]
    public function test_the_edge_and_its_paths_share_one_moment_to_the_second(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C');
        $this->link($tree, $members, '2026-01-01 00:00:00', 'B', 'C');

        $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00.750000'));
        $edge = $tree === 'sponsor'
            ? $this->genealogy()->assignSponsor($members['B'], $members['A'])
            : $this->placement()->place($members['B'], $members['A']);

        $at = $tree === 'sponsor' ? $edge->getAttribute('assigned_at') : $edge->getAttribute('placed_at');
        $this->assertSame('2026-03-01 10:00:00.000000', $at->format('Y-m-d H:i:s.u'));

        $moments = $this->pathMoments($tree);

        foreach (['A > A @0', 'A > B @1', 'A > C @2'] as $path) {
            $this->assertSame('2026-03-01 10:00:00', $moments[$path], $path);
        }
    }

    #[DataProvider('trees')]
    public function test_writes_within_one_second_take_that_second(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C', 'D');

        // Three edges in one stored second, the middle one first.
        $this->link($tree, $members, '2026-05-01 12:00:00.100000', 'B', 'C');
        $this->link($tree, $members, '2026-05-01 12:00:00.500000', 'A', 'B');
        $this->link($tree, $members, '2026-05-01 12:00:00.900000', 'C', 'D');

        $this->assertSame(
            array_fill_keys(array_keys($this->pathMoments($tree)), '2026-05-01 12:00:00'),
            $this->pathMoments($tree),
        );
        $this->assertCount(10, $this->pathMoments($tree));
    }

    public function test_the_two_trees_keep_their_own_moments(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');

        $this->link('sponsor', $members, '2026-01-01 00:00:00', 'Alice', 'Charlie');
        $this->link('placement', $members, '2026-03-01 00:00:00', 'Bob', 'Charlie');

        $this->assertSame([
            'Alice > Alice @0' => '2026-01-01 00:00:00',
            'Alice > Charlie @1' => '2026-01-01 00:00:00',
            'Charlie > Charlie @0' => '2026-01-01 00:00:00',
        ], $this->pathMoments('sponsor'));
        $this->assertSame([
            'Bob > Bob @0' => '2026-03-01 00:00:00',
            'Bob > Charlie @1' => '2026-03-01 00:00:00',
            'Charlie > Charlie @0' => '2026-03-01 00:00:00',
        ], $this->pathMoments('placement'));
    }

    /**
     * `$parent` sponsors `$child`, or `$child` is placed under `$parent`, at
     * `$at`.
     *
     * @param  'sponsor'|'placement'  $tree
     * @param  array<string, Member>  $members
     */
    private function link(string $tree, array $members, string $at, string $parent, string $child): void
    {
        $this->travelTo(CarbonImmutable::parse($at));

        $tree === 'sponsor'
            ? $this->genealogy()->assignSponsor($members[$child], $members[$parent])
            : $this->placement()->place($members[$child], $members[$parent]);
    }
}
