<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use PandaBear\Mlm\Exceptions\CorruptBinaryPlacement;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Volume\Quantity;
use PandaBear\Mlm\Volume\VolumeInput;

/**
 * @internal
 *
 * Binary leg volume: the net volume of one leg of an anchor member — its
 * binary child on that side and everyone below that child in the binary
 * tree — each entry counted only if its member was in that leg when the
 * activity happened. Read by `binary.left.volume` and `binary.right.volume`.
 *
 * - An entry falls in the period its own `effective_at` falls in, [from, until).
 * - Its attribution moment is its own `effective_at`, or for a reversal the
 *   `effective_at` of the entry it reverses, as in every network metric.
 * - It counts only if, by that moment, the anchor's side had been assigned
 *   and the binary path from the child to the entry's member had taken
 *   effect. Both are needed: the child's own paths may be older than its
 *   place under the anchor, and a subtree adopted later joins the leg only
 *   from its adoption.
 * - The anchor's own entries, the other leg's and generic-only descendants'
 *   never count.
 * - With a maximum depth, measured from the anchor: 1 is the child alone.
 *
 * One lookup of the side, then one aggregate query over the binary paths and
 * the volume history: nothing is loaded member by member, nothing is stored.
 */
final readonly class BinaryLegVolumeTotals
{
    private const PATHS = 'mlm_genealogy_paths';

    private const ENTRIES = 'mlm_volume_entries';

    public function __construct(private BinaryGenealogy $genealogy) {}

    /**
     * @throws CorruptBinaryPlacement
     */
    public function forLeg(
        Member $anchor,
        BinarySide $side,
        string $type,
        ?int $maxDepth = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null,
    ): Quantity {
        $type = VolumeInput::identifier('type', $type);
        [$from, $until] = VolumeInput::range($from, $until);

        if ($maxDepth !== null && $maxDepth < 1) {
            throw new InvalidArgumentException("A maximum depth must be 1 or more; {$maxDepth} given.");
        }

        // The anchor as stored, and the leg's root as its position says,
        // checked against its placement edge. An empty side is an empty leg.
        $anchor = $anchor->newQuery()->findOrFail($anchor->getKey());
        $position = $this->genealogy->positionUnder($anchor, $side);

        if ($position === null) {
            return Quantity::fromMillionths(0);
        }

        $connection = $anchor->getConnection();
        $grammar = $connection->getQueryGrammar();

        // The activity's moment: the reversed entry's for a reversal, the
        // entry's own otherwise. Identifiers only, wrapped by the grammar.
        $activity = sprintf('COALESCE(%s, %s)', $grammar->wrap('originals.effective_at'), $grammar->wrap('entries.effective_at'));

        $query = $connection->table(self::ENTRIES.' as entries');

        // Measured, as for the network metrics (ADR-013, ADR-022): without it,
        // MySQL reads a large leg by walking the program's every entry through
        // the (program_id, idempotency_key) key instead of starting from the
        // leg's paths — several times slower. Small legs plan the same either
        // way, and the other databases have no such hint.
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->ignoreIndex($connection->getTablePrefix().self::ENTRIES.'_program_id_idempotency_key_unique');
        }

        $sum = $query
            ->join(self::PATHS.' as paths', 'paths.descendant_id', '=', 'entries.member_id')
            ->leftJoin(self::ENTRIES.' as originals', 'originals.id', '=', 'entries.reversal_of_id')
            ->where('paths.tree_type', 'binary')
            ->where('paths.ancestor_id', $position->member->getKey())
            // The child is one step below the anchor, its self path depth 0.
            ->when($maxDepth !== null, static fn (Builder $query): Builder => $query->where('paths.depth', '<', $maxDepth))
            ->whereRaw($grammar->wrap('paths.effective_from').' <= '.$activity)
            ->whereRaw('? <= '.$activity, [$position->assigned_at])
            ->where('entries.program_id', $anchor->program_id)
            ->where('entries.type', $type)
            ->when($from !== null, static fn (Builder $query): Builder => $query->where('entries.effective_at', '>=', $from))
            ->when($until !== null, static fn (Builder $query): Builder => $query->where('entries.effective_at', '<', $until))
            ->sum('entries.quantity_millionths');

        return Quantity::fromMillionths($sum);
    }
}
