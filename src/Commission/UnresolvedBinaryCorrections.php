<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use Illuminate\Database\Connection;

/**
 * @internal
 *
 * The binary reversals that undid part of a commission's pairing (ADR-025)
 * whose financial share has no adjustment yet (ADR-026) — the one reading
 * of "unresolved" both posting and a period's finalization refuse on.
 * Set-based: one read of the journal and one of the adjustments, however
 * many commissions.
 */
final class UnresolvedBinaryCorrections
{
    private const CHUNK = 500;

    /**
     * @param  list<string>  $commissionIds
     * @return array<string, list<string>> the unresolved reversal ids, sorted, by commission id
     */
    public static function of(Connection $db, array $commissionIds): array
    {
        $unresolved = [];

        foreach (array_chunk($commissionIds, self::CHUNK) as $chunk) {
            $journal = $db->table('mlm_binary_pairing_corrections')
                ->whereIn('commission_id', $chunk)
                ->distinct()
                ->get(['commission_id', 'reversal_volume_entry_id']);

            if ($journal->isEmpty()) {
                continue;
            }

            $resolved = [];

            foreach ($db->table('mlm_commission_adjustments')
                ->whereIn('commission_id', $journal->pluck('commission_id')->unique()->values()->all())
                ->where('type', CommissionAdjustmentEngine::CLAWBACK)
                ->where('source_type', CommissionAdjustmentEngine::BINARY_VOLUME_REVERSAL)
                ->get(['commission_id', 'source_id']) as $adjustment) {
                $resolved[$adjustment->commission_id.'|'.$adjustment->source_id] = true;
            }

            foreach ($journal as $correction) {
                if (! isset($resolved[$correction->commission_id.'|'.$correction->reversal_volume_entry_id])) {
                    $unresolved[(string) $correction->commission_id][] = (string) $correction->reversal_volume_entry_id;
                }
            }
        }

        foreach ($unresolved as &$reversals) {
            $reversals = array_values(array_unique($reversals));
            sort($reversals, SORT_STRING);
        }

        ksort($unresolved, SORT_STRING);

        return $unresolved;
    }
}
