<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Correction;

use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Binary\Pairing\BinaryPairingPlan;
use PandaBear\Mlm\Binary\Pairing\PairingArithmetic;
use PandaBear\Mlm\Exceptions\InvalidBinaryCorrection;

/**
 * @internal
 *
 * Plans how a reversal undoes the pairs its source lot fed (ADR-025) — into
 * the run's plan, writing nothing:
 *
 * - every allocation of the lot that still counts is invalidated, whole:
 *   the entry it was drawn from no longer exists;
 * - the same quantity goes back to the other side of that same pairing, to
 *   the carry lots it was drawn from, newest source first — the FIFO
 *   consumption undone in reverse — never more than an allocation still
 *   counts, and never into a lot whose own source is reversed.
 *
 * The historical allocations, results and commissions are the facts; they
 * are never recalculated or changed. History that cannot be undone exactly
 * is refused as corrupt.
 */
final readonly class BinaryConsumedReversalPlanner
{
    /**
     * @param  object  $lot  the reversed source's stored lot, already registered in the plan
     */
    public function undo(BinaryPairingPlan $plan, BinaryAllocationHistory $history, object $lot, string $reversal, string $original): void
    {
        $id = (string) $lot->id;
        $net = '0';

        foreach ($history->ofLot($id) as $allocation) {
            $net = PairingArithmetic::add($net, $history->net($allocation) ?? throw InvalidBinaryCorrection::corruptLot($id, "allocation [{$allocation->id}] was released more than it consumed"));
        }

        // What it holds and what of it still counts as paired are its whole
        // quantity: otherwise there is no exact undoing.
        if (PairingArithmetic::add($plan->remainingOf($id) ?? '0', $net) !== (string) $lot->quantity_millionths) {
            throw InvalidBinaryCorrection::corruptLot($id, "its remainder and net pairings do not add up to its {$lot->quantity_millionths} millionths");
        }

        foreach ($history->ofLot($id) as $allocation) {
            $quantity = (string) $history->net($allocation);

            if ($quantity === '0') {
                continue;
            }

            $result = $history->results[$allocation->binary_pairing_result_id];
            $plan->expectRelease((string) $allocation->id, $history->invalidated($allocation), $history->restored($allocation));
            $restorations = $this->restore($plan, $history, $allocation, $quantity, $id);
            $history->invalidate($allocation, $quantity);

            $plan->correct([
                'reversal' => $reversal,
                'original' => $original,
                'result' => (string) $result->id,
                'allocation' => (string) $allocation->id,
                'member' => (string) $result->member_id,
                'side' => BinarySide::from($allocation->side),
                'quantity' => $quantity,
                'commission' => $result->commission_id === null ? null : (string) $result->commission_id,
                'restorations' => $restorations,
            ]);
        }
    }

    /**
     * Gives `$quantity` back from the other side of the allocation's pairing
     * result, newest source first.
     *
     * @return list<array{allocation: string, lot: string, side: BinarySide, quantity: string}>
     */
    private function restore(BinaryPairingPlan $plan, BinaryAllocationHistory $history, object $allocation, string $quantity, string $source): array
    {
        $needed = $quantity;
        $restorations = [];

        foreach ($history->opposite($allocation) as $opposite) {
            if ($needed === '0') {
                break;
            }

            $restorable = $history->net($opposite) ?? throw InvalidBinaryCorrection::corruptLot((string) $opposite->binary_carry_lot_id, "allocation [{$opposite->id}] was released more than it consumed");

            if ($restorable === '0') {
                continue;
            }

            $lot = $history->lots[$opposite->binary_carry_lot_id];

            // A reversed source's allocations were undone with it: nothing of
            // one can still count, and none of it may come back.
            if ($lot->reversed_by_volume_entry_id !== null || $plan->isReversed((string) $lot->id)) {
                throw InvalidBinaryCorrection::corruptLot((string) $lot->id, "its source is reversed, yet allocation [{$opposite->id}] still counts {$restorable} millionths paired");
            }

            $take = PairingArithmetic::min($restorable, $needed);
            $plan->expectRelease((string) $opposite->id, $history->invalidated($opposite), $history->restored($opposite));
            $plan->storedLot((string) $lot->id, (string) $lot->member_id, BinarySide::from($lot->side), (string) $lot->source_effective_at, (string) $lot->source_volume_entry_id, (string) $lot->remaining_millionths);
            $plan->restoreStored((string) $lot->id, $take);
            $history->restore($opposite, $take);

            $restorations[] = ['allocation' => (string) $opposite->id, 'lot' => (string) $lot->id, 'side' => BinarySide::from($opposite->side), 'quantity' => $take];
            $needed = PairingArithmetic::subtract($needed, $take);
        }

        if ($needed !== '0') {
            throw InvalidBinaryCorrection::corruptLot($source, "allocation [{$allocation->id}] still counts {$quantity} millionths paired, but the other side of its pairing can give back only part of it");
        }

        return $restorations;
    }
}
