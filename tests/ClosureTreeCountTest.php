<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PandaBear\Mlm\Genealogy\ClosureTree;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;

/**
 * Counting a member's descendants answers exactly what reading them would
 * return — to a depth, and as of a moment — without reading them.
 */
final class ClosureTreeCountTest extends DatabaseTestCase
{
    use BuildsGenealogies;

    public function test_the_count_matches_the_read_at_every_depth_and_moment(): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C', 'D', 'E');

        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->genealogy()->assignSponsor($members['B'], $members['A']);
        $this->genealogy()->assignSponsor($members['C'], $members['A']);
        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));
        $this->genealogy()->assignSponsor($members['D'], $members['B']);
        $this->genealogy()->assignSponsor($members['E'], $members['D']);
        $this->travelBack();

        $tree = new ClosureTree('sponsor');
        $january = CarbonImmutable::parse('2026-01-15 00:00:00');

        foreach ([null, 1, 2, 3] as $depth) {
            $this->assertSame($this->genealogy()->descendants($members['A'], $depth)->count(), $tree->countDescendants($members['A'], $depth));
            $this->assertSame($this->genealogy()->descendantsAt($members['A'], $january, $depth)->count(), $tree->countDescendants($members['A'], $depth, $january));
        }

        $this->assertSame([4, 2, 0], [$tree->countDescendants($members['A'], null), $tree->countDescendants($members['A'], null, $january), (new ClosureTree('placement'))->countDescendants($members['A'], null)]);

        $this->expectException(InvalidArgumentException::class);
        $tree->countDescendants($members['A'], 0);
    }
}
