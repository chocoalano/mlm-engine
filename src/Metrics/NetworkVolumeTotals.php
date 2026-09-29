<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Metrics;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Volume\Quantity;
use PandaBear\Mlm\Volume\VolumeInput;

/**
 * @internal
 *
 * Network volume: the net volume of the members below an anchor member in
 * one genealogy — sponsor, generic placement or matrix — each entry counted
 * for the anchor only if its member was below the anchor when the activity
 * happened. Read by the network metrics. Which genealogy is fixed by the
 * method called, never passed in.
 *
 * - An entry falls in the period its own `effective_at` falls in, [from, until).
 * - It counts for the anchor only if the path from the anchor to its member
 *   had taken effect by the entry's attribution moment: the entry's own
 *   `effective_at`, or for a reversal the `effective_at` of the entry it
 *   reverses — a reversal lands exactly where the activity it corrects
 *   landed, and an ancestor who joined in between receives neither.
 * - The anchor's own entries never count: descendants only, depth 1 and on.
 * - With a maximum depth, only descendants at most that many steps down.
 *   Paths never move, so a path's depth is the depth it had when it took
 *   effect.
 *
 * One aggregate query over the closure table (ADR-007, ADR-012) and the
 * volume history: nothing is loaded member by member, nothing is stored.
 */
final readonly class NetworkVolumeTotals
{
    private const PATHS = 'mlm_genealogy_paths';

    private const ENTRIES = 'mlm_volume_entries';

    public function forSponsorNetwork(
        Member $anchor,
        string $type,
        ?int $maxDepth = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null,
    ): Quantity {
        return $this->total('sponsor', $anchor, $type, $maxDepth, $from, $until);
    }

    public function forPlacementNetwork(
        Member $anchor,
        string $type,
        ?int $maxDepth = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null,
    ): Quantity {
        return $this->total('placement', $anchor, $type, $maxDepth, $from, $until);
    }

    /**
     * Through the matrix paths only (ADR-027): generic-only descendants, and
     * members adopted into the matrix after the activity, never count.
     */
    public function forMatrixNetwork(
        Member $anchor,
        string $type,
        ?int $maxDepth = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null,
    ): Quantity {
        return $this->total('matrix', $anchor, $type, $maxDepth, $from, $until);
    }

    /**
     * @param  'sponsor'|'placement'|'matrix'  $tree
     */
    private function total(
        string $tree,
        Member $anchor,
        string $type,
        ?int $maxDepth,
        ?DateTimeInterface $from,
        ?DateTimeInterface $until,
    ): Quantity {
        $type = VolumeInput::identifier('type', $type);
        [$from, $until] = VolumeInput::range($from, $until);

        if ($maxDepth !== null && $maxDepth < 1) {
            throw new InvalidArgumentException("A maximum depth must be 1 or more; {$maxDepth} given.");
        }

        // The anchor as stored: its program bounds the query, whatever the
        // instance passed in claims.
        $anchor = $anchor->newQuery()->findOrFail($anchor->getKey());
        $connection = $anchor->getConnection();
        $grammar = $connection->getQueryGrammar();

        // The path must have taken effect by the time the activity happened:
        // the reversed entry's moment for a reversal, the entry's own
        // otherwise. Identifiers only, wrapped by the grammar; no input.
        $inEffectForActivity = sprintf(
            '%s <= COALESCE(%s, %s)',
            $grammar->wrap('paths.effective_from'),
            $grammar->wrap('originals.effective_at'),
            $grammar->wrap('entries.effective_at'),
        );

        $query = $connection->table(self::ENTRIES.' as entries');

        // Without this, MySQL applies the program bound below by reading the
        // program's every entry through the (program_id, idempotency_key)
        // key, rather than starting from the anchor's paths: several times
        // slower on large networks (ADR-013). The other databases plan it
        // well, and have no such hint.
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->ignoreIndex($connection->getTablePrefix().self::ENTRIES.'_program_id_idempotency_key_unique');
        }

        $sum = $query
            ->join(self::PATHS.' as paths', 'paths.descendant_id', '=', 'entries.member_id')
            ->leftJoin(self::ENTRIES.' as originals', 'originals.id', '=', 'entries.reversal_of_id')
            ->where('paths.tree_type', $tree)
            ->where('paths.ancestor_id', $anchor->getKey())
            // Depth 0 is the anchor's own path: its own volume is not its network's.
            ->where('paths.depth', '>', 0)
            ->when($maxDepth !== null, static fn (Builder $query): Builder => $query->where('paths.depth', '<=', $maxDepth))
            ->whereRaw($inEffectForActivity)
            ->where('entries.program_id', $anchor->program_id)
            ->where('entries.type', $type)
            ->when($from !== null, static fn (Builder $query): Builder => $query->where('entries.effective_at', '>=', $from))
            ->when($until !== null, static fn (Builder $query): Builder => $query->where('entries.effective_at', '<', $until))
            ->sum('entries.quantity_millionths');

        return Quantity::fromMillionths($sum);
    }
}
