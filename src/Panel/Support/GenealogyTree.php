<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PandaBear\Mlm\Binary\BinaryGenealogy;
use PandaBear\Mlm\Genealogy\ClosureTree;
use PandaBear\Mlm\Genealogy\PlacementGenealogy;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Matrix\MatrixGenealogy;
use PandaBear\Mlm\Models\BinaryPlacementPosition;
use PandaBear\Mlm\Models\MatrixPlacementPosition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\SponsorEdge;

/**
 * One anchor's network, below it and above it, as the explorer shows it.
 *
 * Bounded before it is read: the closure paths are counted first, and only
 * the deepest level that stays within the node cap is loaded — through the
 * network's own genealogy reader, current or as of a moment, exactly as the
 * domain reads it. The direct links that give the nodes their shape come in
 * one more query. No read is made per node, and nothing is written.
 *
 * The depth limit and the node cap protect an interactive page; neither is
 * a rule of any network.
 */
final class GenealogyTree
{
    public const NETWORKS = ['sponsor', 'placement', 'binary', 'matrix'];

    public const MAX_DEPTH = 5;

    public const NODE_CAP = 500;

    /**
     * @return array{
     *     nodes: list<array{id: string, member_code: string, depth: int, parent_code: string|null, side: string|null, slot: int|null, joined_at: string}>,
     *     ancestors: list<string>,
     *     requested_depth: int,
     *     shown_depth: int,
     *     within_requested_depth: int,
     *     truncated: bool
     * }
     */
    public static function read(Member $anchor, string $network, int $depth, ?CarbonImmutable $at = null, int $cap = self::NODE_CAP): array
    {
        $depth = max(1, min(self::MAX_DEPTH, $depth));
        $tree = new ClosureTree($network);
        $total = $tree->countDescendants($anchor, $depth, $at);
        $shown = $depth;
        $count = $total;

        while ($shown > 0 && $count > $cap) {
            $shown--;
            $count = $shown === 0 ? 0 : $tree->countDescendants($anchor, $shown, $at);
        }

        $reader = self::reader($network);
        $relatives = $shown === 0 ? new Collection : ($at === null ? $reader->descendants($anchor, $shown) : $reader->descendantsAt($anchor, $at, $shown));
        $ancestors = $at === null ? $reader->ancestors($anchor, self::MAX_DEPTH) : $reader->ancestorsAt($anchor, $at, self::MAX_DEPTH);

        return [
            'nodes' => self::ordered($anchor, $network, $relatives),
            'ancestors' => $ancestors->map(static fn (object $relative): string => $relative->member->member_code)->values()->all(),
            'requested_depth' => $depth,
            'shown_depth' => $shown,
            'within_requested_depth' => $total,
            'truncated' => $shown < $depth && $total > $cap,
        ];
    }

    private static function reader(string $network): SponsorGenealogy|PlacementGenealogy|BinaryGenealogy|MatrixGenealogy
    {
        return match ($network) {
            'sponsor' => app(SponsorGenealogy::class),
            'placement' => app(PlacementGenealogy::class),
            'binary' => app(BinaryGenealogy::class),
            'matrix' => app(MatrixGenealogy::class),
        };
    }

    /**
     * The relatives in tree order — each under its parent, parents first —
     * with the one direct link each has in this network, read at once.
     *
     * @param  Collection<int, object{member: Member, depth: int}>  $relatives
     * @return list<array{id: string, member_code: string, depth: int, parent_code: string|null, side: string|null, slot: int|null, joined_at: string}>
     */
    private static function ordered(Member $anchor, string $network, Collection $relatives): array
    {
        if ($relatives->isEmpty()) {
            return [];
        }

        $members = [(string) $anchor->getKey() => $anchor];
        $depths = [];

        foreach ($relatives as $relative) {
            $members[(string) $relative->member->getKey()] = $relative->member;
            $depths[(string) $relative->member->getKey()] = $relative->depth;
        }

        $links = self::links($network, array_keys($depths));
        $children = [];

        foreach ($depths as $id => $level) {
            $children[$links[$id]['parent'] ?? ''][] = $id;
        }

        $nodes = [];
        $visit = static function (string $parent) use (&$visit, &$nodes, $children, $members, $depths, $links): void {
            $ids = $children[$parent] ?? [];

            usort($ids, static fn (string $a, string $b): int => [$links[$a]['side'] ?? '', $links[$a]['slot'] ?? 0, $members[$a]->member_code]
                <=> [$links[$b]['side'] ?? '', $links[$b]['slot'] ?? 0, $members[$b]->member_code]);

            foreach ($ids as $id) {
                $nodes[] = [
                    'id' => $id,
                    'member_code' => $members[$id]->member_code,
                    'depth' => $depths[$id],
                    'parent_code' => isset($members[$links[$id]['parent'] ?? '']) ? $members[$links[$id]['parent']]->member_code : null,
                    'side' => $links[$id]['side'] ?? null,
                    'slot' => $links[$id]['slot'] ?? null,
                    'joined_at' => (string) Display::moment($members[$id]->joined_at),
                ];

                $visit($id);
            }
        };

        $visit((string) $anchor->getKey());

        return $nodes;
    }

    /**
     * Each member's one direct link in the network. Edges and positions are
     * never moved, so the link a member has now is the link it had whenever
     * it was in the tree.
     *
     * @param  list<string>  $ids
     * @return array<string, array{parent: string, side?: string, slot?: int}>
     */
    private static function links(string $network, array $ids): array
    {
        return match ($network) {
            'sponsor' => SponsorEdge::query()->whereIn('member_id', $ids)->get(['member_id', 'sponsor_id'])
                ->mapWithKeys(static fn (SponsorEdge $edge): array => [(string) $edge->member_id => ['parent' => (string) $edge->sponsor_id]])->all(),
            'placement' => PlacementEdge::query()->whereIn('member_id', $ids)->get(['member_id', 'parent_id'])
                ->mapWithKeys(static fn (PlacementEdge $edge): array => [(string) $edge->member_id => ['parent' => (string) $edge->parent_id]])->all(),
            'binary' => BinaryPlacementPosition::query()
                ->join('mlm_placement_edges as edge', 'edge.id', '=', 'mlm_binary_placement_positions.placement_edge_id')
                ->whereIn('edge.member_id', $ids)
                ->get(['edge.member_id as member_id', 'mlm_binary_placement_positions.parent_id as parent_id', 'mlm_binary_placement_positions.side as side'])
                ->mapWithKeys(static fn (BinaryPlacementPosition $position): array => [(string) $position->getAttributes()['member_id'] => [
                    'parent' => (string) $position->parent_id,
                    'side' => (string) $position->getAttributes()['side'],
                ]])->all(),
            'matrix' => MatrixPlacementPosition::query()
                ->join('mlm_placement_edges as edge', 'edge.id', '=', 'mlm_matrix_placement_positions.placement_edge_id')
                ->whereIn('edge.member_id', $ids)
                ->get(['edge.member_id as member_id', 'mlm_matrix_placement_positions.parent_id as parent_id', 'mlm_matrix_placement_positions.slot as slot'])
                ->mapWithKeys(static fn (MatrixPlacementPosition $position): array => [(string) $position->getAttributes()['member_id'] => [
                    'parent' => (string) $position->parent_id,
                    'slot' => (int) $position->slot,
                ]])->all(),
        };
    }
}
