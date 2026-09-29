<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Pairing;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * @internal
 *
 * The volume a binary pairing run takes in, read set-based: a chunk of
 * entries at a time, and for each chunk one query over the binary paths
 * and positions — never a query per entry or per ancestor (ADR-023).
 *
 * Attribution follows the binary leg metrics (ADR-022) exactly: an original
 * entry falls in a binary member's leg on a side if, at the entry's own
 * `effective_at`, that side had been assigned and the binary path from the
 * child on it to the entry's member had taken effect. The binary tree as it
 * stood then decides — never the current tree, generic placement or
 * sponsorship — and an entry's own member is never its own ancestor.
 */
final readonly class BinaryPairingSourceEvents
{
    private const ENTRIES = 'mlm_volume_entries';

    private const PATHS = 'mlm_genealogy_paths';

    private const EDGES = 'mlm_placement_edges';

    private const POSITIONS = 'mlm_binary_placement_positions';

    /**
     * Entries read per attribution query.
     */
    private const CHUNK = 500;

    /**
     * Every original of the type whose own moment is in [from, until), once
     * for each binary member whose leg it fell in then: its id, moment and
     * quantity; the member and side; the position that put it there and that
     * position's edge and its parent, for a consistency check; and its reversal, if
     * one is recorded.
     *
     * @return Generator<int, object{entry_id: string, effective_at: string, quantity_millionths: int|string, anchor_id: string, edge_id: string, edge_parent_id: string, side: string, position_id: string, reversal_id: ?string, reversal_effective_at: ?string}>
     */
    public function originals(Connection $db, string $programId, string $type, CarbonImmutable $from, CarbonImmutable $until): Generator
    {
        $last = null;

        do {
            $ids = $db->table(self::ENTRIES)
                ->where('program_id', $programId)
                ->where('type', $type)
                ->whereNull('reversal_of_id')
                ->where('effective_at', '>=', $from)
                ->where('effective_at', '<', $until)
                ->when($last !== null, static fn (Builder $query): Builder => $query->where('id', '>', $last))
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                yield from $this->attributed($db, $ids);
            }

            $last = end($ids);
        } while (count($ids) === self::CHUNK);
    }

    /**
     * Every reversal of the type whose own moment is in [from, until) and
     * whose original's moment is in [earliest, before): its id, and its
     * original's.
     *
     * @return Generator<int, object{reversal_id: string, original_id: string}>
     */
    public function reversals(Connection $db, string $programId, string $type, CarbonImmutable $from, CarbonImmutable $until, CarbonImmutable $earliest, CarbonImmutable $before): Generator
    {
        $last = null;

        do {
            $rows = $db->table(self::ENTRIES.' as reversals')
                ->join(self::ENTRIES.' as originals', 'originals.id', '=', 'reversals.reversal_of_id')
                ->where('reversals.program_id', $programId)
                ->where('reversals.type', $type)
                ->where('reversals.effective_at', '>=', $from)
                ->where('reversals.effective_at', '<', $until)
                ->where('originals.effective_at', '>=', $earliest)
                ->where('originals.effective_at', '<', $before)
                ->when($last !== null, static fn (Builder $query): Builder => $query->where('reversals.id', '>', $last))
                ->orderBy('reversals.id')
                ->limit(self::CHUNK)
                ->get(['reversals.id as reversal_id', 'originals.id as original_id']);

            yield from $rows;

            $last = $rows->last()?->reversal_id;
        } while ($rows->count() === self::CHUNK);
    }

    /**
     * @param  list<string>  $ids
     * @return iterable<int, object>
     */
    private function attributed(Connection $db, array $ids): iterable
    {
        return $db->table(self::ENTRIES.' as entries')
            // Every binary line of the entry's member: its self path makes its
            // own position count, each longer path an ancestor's.
            ->join(self::PATHS.' as paths', static fn (JoinClause $join): JoinClause => $join
                ->on('paths.descendant_id', '=', 'entries.member_id')
                ->where('paths.tree_type', '=', 'binary'))
            ->join(self::EDGES.' as edges', 'edges.member_id', '=', 'paths.ancestor_id')
            ->join(self::POSITIONS.' as positions', 'positions.placement_edge_id', '=', 'edges.id')
            ->leftJoin(self::ENTRIES.' as reversals', 'reversals.reversal_of_id', '=', 'entries.id')
            ->whereIn('entries.id', $ids)
            // In effect when the activity happened: the path, and the side.
            ->whereColumn('paths.effective_from', '<=', 'entries.effective_at')
            ->whereColumn('positions.assigned_at', '<=', 'entries.effective_at')
            ->orderBy('entries.id')
            ->orderBy('positions.parent_id')
            ->get([
                'entries.id as entry_id',
                'entries.effective_at',
                'entries.quantity_millionths',
                'positions.parent_id as anchor_id',
                'edges.id as edge_id',
                'edges.parent_id as edge_parent_id',
                'positions.side',
                'positions.id as position_id',
                'reversals.id as reversal_id',
                'reversals.effective_at as reversal_effective_at',
            ]);
    }
}
