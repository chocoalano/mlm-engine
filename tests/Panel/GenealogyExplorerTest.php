<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Panel;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Binary\BinaryPlacementManager;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Matrix\MatrixNetworkManager;
use PandaBear\Mlm\Matrix\MatrixPlacementManager;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\GenealogyTree;
use PandaBear\Mlm\Program\ProgramManager;

/**
 * The genealogy explorer (ADR-031): one anchor's sponsor, placement, binary
 * or matrix network — current or as of a moment — to a bounded depth and
 * node count, read set-based through the genealogy readers, and never
 * written.
 */
final class GenealogyExplorerTest extends PanelTestCase
{
    private Program $program;

    /** @var array<string, Member> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();

        $programs = $this->app->make(ProgramManager::class);
        $this->program = $programs->create('MAIN', 'Main');

        foreach (['A', 'B', 'C', 'D', 'E'] as $code) {
            $this->members[$code] = $programs->join($this->program, $code, CarbonImmutable::parse('2025-12-01'));
        }

        $this->app->make(MatrixNetworkManager::class)->configure($this->program, 2);

        // January: A > B, A > C. March: B > D, D > E.
        $this->at('2026-01-10', function (): void {
            $this->relate('B', 'A', BinarySide::Left, 1);
            $this->relate('C', 'A', BinarySide::Right, 2);
        });
        $this->at('2026-03-10', function (): void {
            $this->relate('D', 'B', BinarySide::Left, 1);
            $this->relate('E', 'D', BinarySide::Right, 2);
        });

        $this->grant(MlmPermission::NETWORK_VIEW);
    }

    public function test_every_network_shows_its_tree_as_it_stands_and_as_it_stood(): void
    {
        foreach (['sponsor', 'placement', 'binary', 'matrix'] as $network) {
            $now = $this->explore(['network' => $network]);

            $this->assertSame(['B', '— D', '— — E', 'C'], array_column($now['rows'], 'tree'), $network);
            $this->assertSame([1, 2, 3, 1], array_column(array_column($now['rows'], 'depth'), 'raw'), $network);
            $this->assertSame(['A', 'B', 'D', 'A'], array_column($now['rows'], 'parent_code'), $network);

            // In February, D and E had not joined anyone yet.
            $february = $this->explore(['network' => $network, 'as_of' => '2026-02-01 00:00:00']);
            $this->assertSame(['B', 'C'], array_column($february['rows'], 'tree'), "{$network} as of February");
        }

        $this->assertSame(['Left', 'Left', 'Right', 'Right'], array_column($this->explore(['network' => 'binary'])['rows'], 'position'));
        $this->assertSame(['Slot 1', 'Slot 1', 'Slot 2', 'Slot 2'], array_column($this->explore(['network' => 'matrix'])['rows'], 'position'));
        $this->assertSame([null, null, null, null], array_column($this->explore(['network' => 'sponsor'])['rows'], 'position'));
    }

    public function test_the_line_above_the_anchor_is_shown_and_a_root_says_so(): void
    {
        $stats = $this->stats(['member_code' => 'E', 'network' => 'placement']);
        $this->assertSame(['E', 'Above: D ← B ← A'], [$stats[0]['value'], $stats[0]['description']]);

        $root = $this->stats(['member_code' => 'A']);
        $this->assertSame('At the top of this network: no parent.', $root[0]['description']);
    }

    public function test_depth_is_bounded_and_the_node_cap_cuts_depth_rather_than_loading_everything(): void
    {
        $this->assertSame(['B', 'C'], array_column($this->explore(['depth' => '1'])['rows'], 'tree'));
        $this->assertSame(['B', '— D', '— — E', 'C'], array_column($this->explore(['depth' => '99'])['rows'], 'tree'), 'Depth is clamped to 5, not unbounded.');

        $capped = GenealogyTree::read($this->members['A'], 'sponsor', 5, null, 3);
        $this->assertSame([true, 2, 5, 4], [$capped['truncated'], $capped['shown_depth'], $capped['requested_depth'], $capped['within_requested_depth']]);
        $this->assertSame(['B', 'D', 'C'], array_column($capped['nodes'], 'member_code'));

        $nothing = GenealogyTree::read($this->members['A'], 'sponsor', 5, null, 1);
        $this->assertSame([true, 0, []], [$nothing['truncated'], $nothing['shown_depth'], $nothing['nodes']]);
    }

    public function test_an_anchor_is_required_and_found_only_within_the_chosen_program(): void
    {
        $other = $this->app->make(ProgramManager::class)->create('OTHER', 'Other');
        $this->app->make(ProgramManager::class)->join($other, 'X', now());

        $this->assertSame([[], 'Choose a program and the code of one of its members.'], $this->rowsAndEmpty(['member_code' => '']));
        $this->assertSame([[], 'Choose a program and the code of one of its members.'], $this->rowsAndEmpty(['program_id' => $other->id, 'member_code' => 'A']));
        $this->assertSame([[], 'No member is below the anchor in this network at that moment.'], $this->rowsAndEmpty(['member_code' => 'C']));
    }

    public function test_the_explorer_reads_a_fixed_number_of_queries_and_writes_nothing(): void
    {
        $small = $this->queries(static fn () => GenealogyTree::read(Member::query()->where('member_code', 'A')->sole(), 'binary', 5));

        $programs = $this->app->make(ProgramManager::class);
        $parent = $this->members['C'];

        foreach (range(1, 6) as $i) {
            $member = $programs->join($this->program, "N{$i}", CarbonImmutable::parse('2025-12-01'));
            $this->app->make(BinaryPlacementManager::class)->place($member, $parent, $i % 2 === 0 ? BinarySide::Right : BinarySide::Left);

            if ($i % 2 === 0) {
                $parent = $member;
            }
        }

        $large = $this->queries(static fn () => GenealogyTree::read(Member::query()->where('member_code', 'A')->sole(), 'binary', 5));

        $this->assertSame(count($small), count($large));
        $this->assertGreaterThan(4, count(GenealogyTree::read($this->members['A'], 'binary', 5)['nodes']));

        $page = $this->queries(fn () => $this->explore(['network' => 'binary']));
        foreach ($page as $query) {
            $this->assertMatchesRegularExpression('/^\s*select/i', $query, 'The explorer wrote.');
        }
    }

    public function test_the_explorer_is_for_the_network_view_capability_only(): void
    {
        $this->grant(MlmPermission::MEMBERS_VIEW, MlmPermission::NETWORK_OPERATE);
        $this->withHeaders(['X-Inertia' => 'true'])->get('/mlm/mlm-genealogy')->assertForbidden();

        $this->grant(MlmPermission::NETWORK_VIEW);
        $this->withHeaders(['X-Inertia' => 'true'])->get('/mlm/mlm-genealogy')->assertOk()->assertJsonPath('component', 'panel/Page');
    }

    private function at(string $moment, callable $callback): void
    {
        $this->travelTo(CarbonImmutable::parse($moment));
        $callback();
        $this->travelBack();
    }

    private function relate(string $member, string $parent, BinarySide $side, int $slot): void
    {
        $this->app->make(SponsorGenealogy::class)->assignSponsor($this->members[$member], $this->members[$parent]);
        $this->app->make(BinaryPlacementManager::class)->place($this->members[$member], $this->members[$parent], $side);
        $this->app->make(MatrixPlacementManager::class)->adopt(PlacementEdge::query()->where('member_id', $this->members[$member]->id)->sole(), $slot);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function page(array $filters): array
    {
        return $this->withHeaders(['X-Inertia' => 'true'])
            ->get('/mlm/mlm-genealogy?'.http_build_query(['filters' => ['program_id' => $this->program->id, 'member_code' => 'A', 'network' => 'sponsor', 'depth' => '5', ...$filters]]))
            ->assertOk()
            ->json('props.widgets');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>}
     */
    private function explore(array $filters): array
    {
        $tree = $this->page($filters)[1]['data'];

        return ['rows' => array_column($tree['rows'], 'cells')];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function stats(array $filters): array
    {
        return $this->page($filters)[0]['data']['stats'];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{list<mixed>, string}
     */
    private function rowsAndEmpty(array $filters): array
    {
        $tree = $this->page($filters)[1]['data'];

        return [$tree['rows'], $tree['emptyMessage']];
    }

    /**
     * @return list<string>
     */
    private function queries(callable $callback): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $callback();
        $log = array_column($connection->getQueryLog(), 'query');
        $connection->disableQueryLog();

        return $log;
    }
}
