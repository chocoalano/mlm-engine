<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Binary\BinaryGenealogy;
use PandaBear\Mlm\Binary\BinaryPlacementManager;
use PandaBear\Mlm\Binary\BinaryRelative;
use PandaBear\Mlm\Genealogy\PlacementGenealogy;
use PandaBear\Mlm\Genealogy\PlacementRelative;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Genealogy\SponsorRelative;
use PandaBear\Mlm\Matrix\MatrixGenealogy;
use PandaBear\Mlm\Matrix\MatrixNetworkManager;
use PandaBear\Mlm\Matrix\MatrixPlacementManager;
use PandaBear\Mlm\Matrix\MatrixRelative;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;

/**
 * Sponsor and placement trees built the supported way — through the
 * genealogy services — and read back as strings a failure message can show.
 */
trait BuildsGenealogies
{
    protected function genealogy(): SponsorGenealogy
    {
        return $this->app->make(SponsorGenealogy::class);
    }

    protected function placement(): PlacementGenealogy
    {
        return $this->app->make(PlacementGenealogy::class);
    }

    protected function binary(): BinaryPlacementManager
    {
        return $this->app->make(BinaryPlacementManager::class);
    }

    protected function binaryTree(): BinaryGenealogy
    {
        return $this->app->make(BinaryGenealogy::class);
    }

    protected function matrixNetworks(): MatrixNetworkManager
    {
        return $this->app->make(MatrixNetworkManager::class);
    }

    protected function matrix(): MatrixPlacementManager
    {
        return $this->app->make(MatrixPlacementManager::class);
    }

    protected function matrixTree(): MatrixGenealogy
    {
        return $this->app->make(MatrixGenealogy::class);
    }

    /**
     * @return array<string, Member> keyed by member code
     */
    protected function members(Program $program, string ...$codes): array
    {
        $members = [];

        foreach ($codes as $code) {
            $members[$code] = Member::factory()->for($program)->create(['member_code' => $code]);
        }

        return $members;
    }

    /**
     * @param  array<string, Member>  $members
     * @param  array<string, list<string>>  $tree  sponsor code => the codes it sponsors, in order
     */
    protected function sponsorTree(array $members, array $tree): void
    {
        foreach ($tree as $sponsor => $sponsored) {
            foreach ($sponsored as $code) {
                $this->genealogy()->assignSponsor($members[$code], $members[$sponsor]);
            }
        }
    }

    /**
     * @param  array<string, Member>  $members
     * @param  array<string, list<string>>  $tree  parent code => the codes placed under it, in order
     */
    protected function placementTree(array $members, array $tree): void
    {
        foreach ($tree as $parent => $children) {
            foreach ($children as $code) {
                $this->placement()->place($members[$code], $members[$parent]);
            }
        }
    }

    /**
     * Every sponsor edge, and every genealogy path of either tree, by member
     * code.
     *
     * @return array{edges: list<string>, paths: list<string>}
     */
    protected function genealogyState(?string $connection = null): array
    {
        $codes = Member::query()->pluck('member_code', 'id');
        $db = DB::connection($connection);

        return [
            'edges' => $db->table('mlm_sponsor_edges')->get()
                ->map(static fn (object $edge): string => "{$codes[$edge->sponsor_id]} > {$codes[$edge->member_id]}")
                ->sort()->values()->all(),
            'paths' => $db->table('mlm_genealogy_paths')->get()
                ->map(static fn (object $path): string => "{$path->tree_type}: {$codes[$path->ancestor_id]} > {$codes[$path->descendant_id]} @{$path->depth}")
                ->sort()->values()->all(),
        ];
    }

    /**
     * The sponsor tree alone: its edges and its paths.
     *
     * @return array{edges: list<string>, paths: list<string>}
     */
    protected function sponsorState(?string $connection = null): array
    {
        return $this->treeState('sponsor', 'mlm_sponsor_edges', 'sponsor_id', $connection);
    }

    /**
     * The placement tree alone: its edges and its paths.
     *
     * @return array{edges: list<string>, paths: list<string>}
     */
    protected function placementState(?string $connection = null): array
    {
        return $this->treeState('placement', 'mlm_placement_edges', 'parent_id', $connection);
    }

    /**
     * The binary overlay alone: its positions, with their side and moment,
     * and its paths.
     *
     * @return array{positions: list<string>, paths: list<string>}
     */
    protected function binaryState(?string $connection = null): array
    {
        $codes = Member::query()->pluck('member_code', 'id');
        $db = DB::connection($connection);

        return [
            'positions' => $db->table('mlm_binary_placement_positions as positions')
                ->join('mlm_placement_edges as edges', 'edges.id', '=', 'positions.placement_edge_id')
                ->get(['positions.parent_id', 'positions.side', 'positions.assigned_at', 'edges.member_id'])
                ->map(static fn (object $position): string => "{$codes[$position->parent_id]} > {$codes[$position->member_id]} {$position->side} @{$position->assigned_at}")
                ->sort()->values()->all(),
            'paths' => $this->treeState('binary', 'mlm_placement_edges', 'parent_id', $connection)['paths'],
        ];
    }

    /**
     * The matrix overlay alone: its positions, with their slot and moment,
     * and its paths.
     *
     * @return array{positions: list<string>, paths: list<string>}
     */
    protected function matrixState(?string $connection = null): array
    {
        $codes = Member::query()->pluck('member_code', 'id');
        $db = DB::connection($connection);

        return [
            'positions' => $db->table('mlm_matrix_placement_positions as positions')
                ->join('mlm_placement_edges as edges', 'edges.id', '=', 'positions.placement_edge_id')
                ->get(['positions.parent_id', 'positions.slot', 'positions.assigned_at', 'edges.member_id'])
                ->map(static fn (object $position): string => "{$codes[$position->parent_id]} > {$codes[$position->member_id]} #{$position->slot} @{$position->assigned_at}")
                ->sort()->values()->all(),
            'paths' => $this->treeState('matrix', 'mlm_placement_edges', 'parent_id', $connection)['paths'],
        ];
    }

    /**
     * When each path of one tree took effect, exactly as stored, by member
     * code.
     *
     * @param  'sponsor'|'placement'|'binary'|'matrix'  $tree
     * @return array<string, string> "A > B @1" => "2026-01-01 10:00:00", by path
     */
    protected function pathMoments(string $tree, ?string $connection = null): array
    {
        $codes = Member::query()->pluck('member_code', 'id');

        $moments = DB::connection($connection)->table('mlm_genealogy_paths')->where('tree_type', $tree)->get()
            ->mapWithKeys(static fn (object $path): array => [
                "{$codes[$path->ancestor_id]} > {$codes[$path->descendant_id]} @{$path->depth}" => (string) $path->effective_from,
            ])
            ->all();

        ksort($moments);

        return $moments;
    }

    /**
     * @param  Collection<int, SponsorRelative|PlacementRelative|BinaryRelative|MatrixRelative>  $relatives
     * @return list<string> "code@depth", in the order given
     */
    protected function relatives(Collection $relatives): array
    {
        return $relatives->map(static fn (SponsorRelative|PlacementRelative|BinaryRelative|MatrixRelative $relative): string => "{$relative->member->member_code}@{$relative->depth}")->all();
    }

    /**
     * @return array{edges: list<string>, paths: list<string>}
     */
    private function treeState(string $tree, string $edges, string $parentColumn, ?string $connection): array
    {
        $codes = Member::query()->pluck('member_code', 'id');
        $db = DB::connection($connection);

        return [
            'edges' => $db->table($edges)->get()
                ->map(static fn (object $edge): string => "{$codes[$edge->{$parentColumn}]} > {$codes[$edge->member_id]}")
                ->sort()->values()->all(),
            'paths' => $db->table('mlm_genealogy_paths')->where('tree_type', $tree)->get()
                ->map(static fn (object $path): string => "{$codes[$path->ancestor_id]} > {$codes[$path->descendant_id]} @{$path->depth}")
                ->sort()->values()->all(),
        ];
    }
}
