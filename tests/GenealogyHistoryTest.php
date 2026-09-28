<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The `…At()` queries read a genealogy as it stood at a moment: a
 * relationship is there from the second it took effect, inclusive, and was
 * not there before. The queries without a moment keep reading the tree as it
 * stands.
 */
final class GenealogyHistoryTest extends DatabaseTestCase
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
    public function test_a_member_linked_later_was_a_root_before(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B');
        $this->link($tree, $members, '2026-03-01 00:00:00', 'A', 'B');

        $this->assertNull($this->parentAt($tree, $members['B'], '2026-02-01 00:00:00'));
        $this->assertSame([], $this->ancestorsAt($tree, $members['B'], '2026-02-01 00:00:00'));
        $this->assertSame([], $this->childrenAt($tree, $members['A'], '2026-02-01 00:00:00'));
        $this->assertSame([], $this->descendantsAt($tree, $members['A'], '2026-02-01 00:00:00'));

        $this->assertTrue($this->parentAt($tree, $members['B'], '2026-03-01 00:00:00')?->is($members['A']));
        $this->assertSame(['A@1'], $this->ancestorsAt($tree, $members['B'], '2026-03-01 00:00:00'));

        // Today it has its parent: the current query knows no history.
        $current = $tree === 'sponsor'
            ? $this->genealogy()->directSponsor($members['B'])
            : $this->placement()->directParent($members['B']);
        $this->assertTrue($current?->is($members['A']));
    }

    #[DataProvider('trees')]
    public function test_the_second_a_relationship_takes_effect_is_included(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C');
        $this->link($tree, $members, '2026-01-01 00:00:00', 'A', 'B');
        $this->link($tree, $members, '2026-04-01 10:00:00', 'B', 'C');

        foreach (['2026-04-01 09:59:59', '2026-04-01 09:59:59.999999'] as $before) {
            $this->assertNull($this->parentAt($tree, $members['C'], $before), $before);
            $this->assertSame([], $this->childrenAt($tree, $members['B'], $before), $before);
            $this->assertSame([], $this->ancestorsAt($tree, $members['C'], $before), $before);
            $this->assertSame(['B@1'], $this->descendantsAt($tree, $members['A'], $before), $before);
        }

        foreach (['2026-04-01 10:00:00', '2026-04-01 10:00:00.000001', '2026-04-01 10:00:01'] as $from) {
            $this->assertTrue($this->parentAt($tree, $members['C'], $from)?->is($members['B']), $from);
            $this->assertSame(['C'], $this->childrenAt($tree, $members['B'], $from), $from);
            $this->assertSame(['B@1', 'A@2'], $this->ancestorsAt($tree, $members['C'], $from), $from);
            $this->assertSame(['B@1', 'C@2'], $this->descendantsAt($tree, $members['A'], $from), $from);
        }
    }

    #[DataProvider('trees')]
    public function test_one_instant_reads_the_same_in_any_timezone(string $tree): void
    {
        // The application runs on UTC; the edge takes effect at 10:00:00 UTC.
        $this->assertSame('UTC', date_default_timezone_get());
        $members = $this->members(Program::factory()->create(), 'A', 'B');
        $this->link($tree, $members, '2026-04-01 10:00:00', 'A', 'B');

        $before = [
            new DateTime('2026-04-01 09:59:59', new DateTimeZone('UTC')),
            CarbonImmutable::parse('2026-04-01 16:59:59', 'Asia/Jakarta'),
            new DateTime('2026-04-01 05:59:59', new DateTimeZone('America/New_York')),
        ];
        $from = [
            new DateTime('2026-04-01 10:00:00', new DateTimeZone('UTC')),
            CarbonImmutable::parse('2026-04-01 17:00:00', 'Asia/Jakarta'),
            new DateTime('2026-04-01 06:00:00', new DateTimeZone('America/New_York')),
        ];

        foreach ($before as $at) {
            $this->assertNull($this->parentAt($tree, $members['B'], $at), $at->format(DATE_ATOM));
            $this->assertSame([], $this->descendantsAt($tree, $members['A'], $at), $at->format(DATE_ATOM));
        }

        foreach ($from as $at) {
            $this->assertTrue($this->parentAt($tree, $members['B'], $at)?->is($members['A']), $at->format(DATE_ATOM));
            $this->assertSame(['B@1'], $this->descendantsAt($tree, $members['A'], $at), $at->format(DATE_ATOM));
            $this->assertSame(['A@1'], $this->ancestorsAt($tree, $members['B'], $at), $at->format(DATE_ATOM));
            $this->assertSame(['B'], $this->childrenAt($tree, $members['A'], $at), $at->format(DATE_ATOM));
        }
    }

    #[DataProvider('trees')]
    public function test_a_subtree_enters_the_history_when_it_is_attached(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C', 'D');
        $this->link($tree, $members, '2026-01-01 00:00:00', 'B', 'C');
        $this->link($tree, $members, '2026-02-01 00:00:00', 'C', 'D');
        $this->link($tree, $members, '2026-03-01 00:00:00', 'A', 'B');

        $february = '2026-02-15 00:00:00';
        $this->assertSame(['C@1', 'D@2'], $this->descendantsAt($tree, $members['B'], $february));
        $this->assertSame(['D@1'], $this->descendantsAt($tree, $members['C'], $february));
        $this->assertSame(['C@1', 'B@2'], $this->ancestorsAt($tree, $members['D'], $february));
        $this->assertSame([], $this->descendantsAt($tree, $members['A'], $february));
        $this->assertSame([], $this->ancestorsAt($tree, $members['B'], $february));
        $this->assertNull($this->parentAt($tree, $members['B'], $february));

        $march = '2026-03-01 00:00:00';
        $this->assertSame(['B@1', 'C@2', 'D@3'], $this->descendantsAt($tree, $members['A'], $march));
        $this->assertSame(['C@1', 'B@2', 'A@3'], $this->ancestorsAt($tree, $members['D'], $march));
        $this->assertTrue($this->parentAt($tree, $members['B'], $march)?->is($members['A']));
    }

    #[DataProvider('trees')]
    public function test_branches_appear_as_each_edge_takes_effect(string $tree): void
    {
        $members = $this->branches($tree);

        $this->assertSame([], $this->descendantsAt($tree, $members['A'], '2026-01-01 00:00:00'));
        $this->assertSame(['B@1'], $this->descendantsAt($tree, $members['A'], '2026-01-15 00:00:00'));
        $this->assertSame(['B@1', 'C@1'], $this->descendantsAt($tree, $members['A'], '2026-02-15 00:00:00'));
        $this->assertSame(['B@1', 'C@1', 'D@2'], $this->descendantsAt($tree, $members['A'], '2026-03-15 00:00:00'));
        $this->assertSame(['B@1', 'C@1', 'D@2', 'E@2'], $this->descendantsAt($tree, $members['A'], '2026-05-01 00:00:00'));

        $this->assertSame([], $this->descendantsAt($tree, $members['C'], '2026-03-15 00:00:00'));
        $this->assertSame(['E@1'], $this->descendantsAt($tree, $members['C'], '2026-05-01 00:00:00'));

        $this->assertSame(['B@1', 'A@2'], $this->ancestorsAt($tree, $members['D'], '2026-03-15 00:00:00'));
        $this->assertSame([], $this->ancestorsAt($tree, $members['E'], '2026-03-15 00:00:00'));
        $this->assertSame(['C@1', 'A@2'], $this->ancestorsAt($tree, $members['E'], '2026-05-01 00:00:00'));

        $this->assertSame(['B'], $this->childrenAt($tree, $members['A'], '2026-01-15 00:00:00'));
        $this->assertSame(['B', 'C'], $this->childrenAt($tree, $members['A'], '2026-05-01 00:00:00'));
    }

    #[DataProvider('trees')]
    public function test_a_maximum_depth_limits_a_historical_query(string $tree): void
    {
        $members = $this->branches($tree);

        $this->assertSame(['B@1', 'C@1'], $this->descendantsAt($tree, $members['A'], '2026-05-01 00:00:00', maxDepth: 1));
        $this->assertSame(['B@1'], $this->descendantsAt($tree, $members['A'], '2026-01-15 00:00:00', maxDepth: 1));
        $this->assertSame(['C@1'], $this->ancestorsAt($tree, $members['E'], '2026-05-01 00:00:00', maxDepth: 1));
        $this->assertSame(['C@1', 'A@2'], $this->ancestorsAt($tree, $members['E'], '2026-05-01 00:00:00', maxDepth: 2));
    }

    #[DataProvider('trees')]
    public function test_a_historical_maximum_depth_below_one_is_refused(string $tree): void
    {
        $members = $this->branches($tree);

        foreach ([0, -1] as $maxDepth) {
            foreach (['ancestorsAt', 'descendantsAt'] as $query) {
                try {
                    $this->{$query}($tree, $members['B'], '2026-05-01 00:00:00', $maxDepth);
                    $this->fail("{$query} accepted maxDepth {$maxDepth}.");
                } catch (InvalidArgumentException $exception) {
                    $this->assertStringContainsString('maximum depth must be 1 or more', $exception->getMessage());
                }
            }
        }
    }

    public function test_sponsor_and_placement_history_are_independent(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');
        $this->link('sponsor', $members, '2026-01-01 00:00:00', 'Alice', 'Charlie');
        $this->link('placement', $members, '2026-03-01 00:00:00', 'Bob', 'Charlie');

        $february = '2026-02-01 00:00:00';
        $this->assertTrue($this->parentAt('sponsor', $members['Charlie'], $february)?->is($members['Alice']));
        $this->assertNull($this->parentAt('placement', $members['Charlie'], $february));
        $this->assertSame(['Alice@1'], $this->ancestorsAt('sponsor', $members['Charlie'], $february));
        $this->assertSame([], $this->ancestorsAt('placement', $members['Charlie'], $february));
        $this->assertSame(['Charlie@1'], $this->descendantsAt('sponsor', $members['Alice'], $february));
        $this->assertSame([], $this->descendantsAt('placement', $members['Bob'], $february));

        $april = '2026-04-01 00:00:00';
        $this->assertTrue($this->parentAt('sponsor', $members['Charlie'], $april)?->is($members['Alice']));
        $this->assertTrue($this->parentAt('placement', $members['Charlie'], $april)?->is($members['Bob']));
        $this->assertSame(['Alice@1'], $this->ancestorsAt('sponsor', $members['Charlie'], $april));
        $this->assertSame(['Bob@1'], $this->ancestorsAt('placement', $members['Charlie'], $april));

        // Neither tree reads the other's paths or edges.
        $this->assertSame([], $this->descendantsAt('placement', $members['Alice'], $april));
        $this->assertSame([], $this->descendantsAt('sponsor', $members['Bob'], $april));
        $this->assertSame([], $this->childrenAt('placement', $members['Alice'], $april));
        $this->assertSame([], $this->childrenAt('sponsor', $members['Bob'], $april));
    }

    public function test_reading_history_writes_nothing(): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C', 'Outsider');
        $this->link('sponsor', $members, '2026-01-01 00:00:00', 'A', 'B');
        $this->link('placement', $members, '2026-02-01 00:00:00', 'B', 'C');
        $before = [$this->sponsorState(), $this->placementState()];

        foreach (['sponsor', 'placement'] as $tree) {
            foreach ($members as $member) {
                foreach (['2025-12-01 00:00:00', '2026-01-01 00:00:00', '2026-06-01 00:00:00'] as $at) {
                    $this->parentAt($tree, $member, $at);
                    $this->childrenAt($tree, $member, $at);
                    $this->ancestorsAt($tree, $member, $at);
                    $this->descendantsAt($tree, $member, $at);
                }
            }
        }

        $this->assertSame($before, [$this->sponsorState(), $this->placementState()]);
    }

    #[DataProvider('trees')]
    public function test_the_current_queries_do_not_depend_on_the_clock(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B');
        $this->link($tree, $members, '2026-03-01 00:00:00', 'A', 'B');

        // A clock set before the edge changes nothing about the tree as it stands.
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));

        $descendants = $tree === 'sponsor'
            ? $this->genealogy()->descendants($members['A'])
            : $this->placement()->descendants($members['A']);
        $this->assertSame(['B@1'], $this->relatives($descendants));
    }

    /**
     * A ├ B (Jan 10) └ D (Mar 10), A ├ C (Feb 10) └ E (Apr 10).
     *
     * @param  'sponsor'|'placement'  $tree
     * @return array<string, Member>
     */
    private function branches(string $tree): array
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C', 'D', 'E');

        $this->link($tree, $members, '2026-01-10 00:00:00', 'A', 'B');
        $this->link($tree, $members, '2026-02-10 00:00:00', 'A', 'C');
        $this->link($tree, $members, '2026-03-10 00:00:00', 'B', 'D');
        $this->link($tree, $members, '2026-04-10 00:00:00', 'C', 'E');

        return $members;
    }

    /**
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

    private function parentAt(string $tree, Member $member, string|DateTimeInterface $at): ?Member
    {
        return $tree === 'sponsor'
            ? $this->genealogy()->directSponsorAt($member, $this->moment($at))
            : $this->placement()->directParentAt($member, $this->moment($at));
    }

    /**
     * @return list<string> member codes, in order
     */
    private function childrenAt(string $tree, Member $member, string|DateTimeInterface $at): array
    {
        $children = $tree === 'sponsor'
            ? $this->genealogy()->directMembersAt($member, $this->moment($at))
            : $this->placement()->directChildrenAt($member, $this->moment($at));

        return $children->pluck('member_code')->all();
    }

    /**
     * @return list<string> "code@depth", in order
     */
    private function ancestorsAt(string $tree, Member $member, string|DateTimeInterface $at, ?int $maxDepth = null): array
    {
        return $this->relatives($tree === 'sponsor'
            ? $this->genealogy()->ancestorsAt($member, $this->moment($at), $maxDepth)
            : $this->placement()->ancestorsAt($member, $this->moment($at), $maxDepth));
    }

    /**
     * @return list<string> "code@depth", in order
     */
    private function descendantsAt(string $tree, Member $member, string|DateTimeInterface $at, ?int $maxDepth = null): array
    {
        return $this->relatives($tree === 'sponsor'
            ? $this->genealogy()->descendantsAt($member, $this->moment($at), $maxDepth)
            : $this->placement()->descendantsAt($member, $this->moment($at), $maxDepth));
    }

    private function moment(string|DateTimeInterface $at): DateTimeInterface
    {
        return is_string($at) ? CarbonImmutable::parse($at) : $at;
    }
}
