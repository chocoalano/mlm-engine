<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Correction;

use Illuminate\Database\Connection;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Binary\Pairing\PairingArithmetic;
use PandaBear\Mlm\Exceptions\InvalidBinaryCorrection;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Volume\Quantity;

/**
 * Answers, read-only, what a volume reversal reaches in binary pairing
 * (ADR-024): every carry lot its original created, in every component and
 * binary member's leg; how much of each remains and how much pairings
 * consumed; which pairing results, runs and commissions consumed it.
 *
 * The stored allocations are the history: nothing is recalculated — no
 * pairing, genealogy, pair count, amount or rounding — and nothing is
 * written. A reversal that is not one, or carry that no pairing run could
 * have left, is refused rather than reported.
 *
 * Consumptions are ordered by their run — its range, then its id — then by
 * pairing result and allocation id.
 */
final readonly class BinaryReversalImpactAnalyzer
{
    private const LOTS = 'mlm_binary_carry_lots';

    private const ALLOCATIONS = 'mlm_binary_pairing_allocations';

    private const RESULTS = 'mlm_binary_pairing_results';

    private const RUNS = 'mlm_calculation_runs';

    private const COMMISSIONS = 'mlm_commissions';

    private const CHUNK = 500;

    /**
     * @throws InvalidBinaryCorrection
     */
    public function analyze(VolumeEntry $reversal): BinaryReversalImpact
    {
        $db = $reversal->getConnection();
        [$reversal, $original] = $this->entries($db, (string) $reversal->getKey());

        $lots = $db->table(self::LOTS)
            ->where('source_volume_entry_id', $original->getKey())
            ->orderBy('plan_component_id')
            ->orderBy('member_id')
            ->orderBy('side')
            ->orderBy('id')
            ->get();

        $consumptions = $this->consumptions($db, $lots->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());
        $impacts = [];

        foreach ($lots as $lot) {
            $impacts[] = $this->lot($lot, $original, $reversal, $consumptions[$lot->id] ?? []);
        }

        return new BinaryReversalImpact(
            (string) $original->program_id,
            (string) $original->getKey(),
            (string) $reversal->getKey(),
            $reversal->effective_at->format('Y-m-d H:i:s'),
            $impacts,
        );
    }

    /**
     * The stored reversal and its original, held to the volume history's
     * own rules.
     *
     * @return array{VolumeEntry, VolumeEntry}
     */
    private function entries(Connection $db, string $id): array
    {
        $reversal = VolumeEntry::on($db->getName())->find($id) ?? throw InvalidBinaryCorrection::missing('volume entry', $id);

        if ($reversal->reversal_of_id === null) {
            throw InvalidBinaryCorrection::notAReversal($id);
        }

        $original = VolumeEntry::on($db->getName())->find($reversal->reversal_of_id)
            ?? throw InvalidBinaryCorrection::missing('reversed volume entry', $reversal->reversal_of_id);

        $problem = match (true) {
            $original->reversal_of_id !== null => 'the entry it names is itself a reversal',
            $original->program_id !== $reversal->program_id => 'they belong to different programs',
            $original->member_id !== $reversal->member_id => 'they belong to different members',
            $original->type !== $reversal->type => 'they are of different volume types',
            ! $reversal->quantity->equals($original->quantity->negate()) => 'its quantity is not exactly the original\'s, negated',
            default => null,
        };

        if ($problem !== null) {
            throw InvalidBinaryCorrection::inconsistentReversal($id, (string) $original->getKey(), $problem);
        }

        return [$reversal, $original];
    }

    /**
     * Every allocation of the lots, with its pairing result, run and
     * commission — a few queries per chunk of lots, never one per
     * allocation.
     *
     * @param  list<string>  $lotIds
     * @return array<string, list<array{object, object, object, ?object}>> by lot, in consumption order
     */
    private function consumptions(Connection $db, array $lotIds): array
    {
        $allocations = [];

        foreach (array_chunk($lotIds, self::CHUNK) as $chunk) {
            foreach ($db->table(self::ALLOCATIONS)->whereIn('binary_carry_lot_id', $chunk)->get() as $allocation) {
                $allocations[] = $allocation;
            }
        }

        $results = $this->byId($db, self::RESULTS, array_column($allocations, 'binary_pairing_result_id'));
        $runs = $this->byId($db, self::RUNS, array_column($results, 'calculation_run_id'));
        $commissions = $this->byId($db, self::COMMISSIONS, array_values(array_filter(array_column($results, 'commission_id'))));
        $byLot = [];

        foreach ($allocations as $allocation) {
            $result = $results[$allocation->binary_pairing_result_id];
            $byLot[$allocation->binary_carry_lot_id][] = [
                $allocation,
                $result,
                $runs[$result->calculation_run_id],
                $result->commission_id === null ? null : $commissions[$result->commission_id],
            ];
        }

        foreach ($byLot as &$rows) {
            usort($rows, static fn (array $a, array $b): int => [(string) $a[2]->from_at, (string) $a[2]->until_at, $a[2]->id, $a[1]->id, $a[0]->id]
                <=> [(string) $b[2]->from_at, (string) $b[2]->until_at, $b[2]->id, $b[1]->id, $b[0]->id]);
        }

        return $byLot;
    }

    /**
     * @param  list<array{object, object, object, ?object}>  $consumptions
     */
    private function lot(object $lot, VolumeEntry $original, VolumeEntry $reversal, array $consumptions): BinaryReversalLotImpact
    {
        $id = (string) $lot->id;
        $side = BinarySide::parse($lot->side) ?? throw InvalidBinaryCorrection::corruptLot($id, 'its side is '.var_export($lot->side, true));
        $quantity = (string) $lot->quantity_millionths;
        $remaining = (string) $lot->remaining_millionths;

        if ($lot->program_id !== $original->program_id) {
            throw InvalidBinaryCorrection::corruptLot($id, "it belongs to program [{$lot->program_id}], its source entry to [{$original->program_id}]");
        }

        if (preg_match('/^[1-9]\d*$/D', $quantity) !== 1 || preg_match('/^(0|[1-9]\d*)$/D', $remaining) !== 1) {
            throw InvalidBinaryCorrection::corruptLot($id, "it holds {$remaining} of {$quantity} millionths");
        }

        $consumed = '0';
        $impacts = [];

        foreach ($consumptions as [$allocation, $result, $run, $commission]) {
            $taken = (string) $allocation->quantity_millionths;

            if (preg_match('/^[1-9]\d*$/D', $taken) !== 1 || $allocation->side !== $side->value || $result->plan_component_id !== $lot->plan_component_id || $result->member_id !== $lot->member_id) {
                throw InvalidBinaryCorrection::corruptLot($id, "allocation [{$allocation->id}] does not draw on it as its pairing result's own carry");
            }

            $consumed = PairingArithmetic::add($consumed, $taken);
            $impacts[] = new BinaryReversalConsumptionImpact(
                allocationId: (string) $allocation->id,
                side: $side->value,
                quantity: Quantity::fromMillionths($taken)->value(),
                pairingResultId: (string) $result->id,
                calculationRunId: (string) $run->id,
                runFrom: substr((string) $run->from_at, 0, 19),
                runUntil: substr((string) $run->until_at, 0, 19),
                earningMemberId: (string) $result->member_id,
                pairingConsumedQuantity: (string) $result->consumed_quantity,
                commissionId: $commission === null ? null : (string) $commission->id,
                commissionStatus: $commission === null ? null : (string) $commission->status,
                commissionAmount: $commission === null ? null : FinancialAmount::fromMillionths((string) $commission->amount_millionths)->value(),
                commissionCurrency: $commission === null ? null : (string) $commission->currency,
            );
        }

        return new BinaryReversalLotImpact(
            lotId: $id,
            planComponentId: (string) $lot->plan_component_id,
            memberId: (string) $lot->member_id,
            side: $side->value,
            originalQuantity: Quantity::fromMillionths($quantity)->value(),
            remainingQuantity: Quantity::fromMillionths($remaining)->value(),
            consumedQuantity: Quantity::fromMillionths($consumed)->value(),
            state: $this->state($id, $lot, $reversal, $quantity, $remaining, $consumed),
            consumptions: $impacts,
        );
    }

    /**
     * The lot's state, once its numbers agree: an unreversed lot's remainder
     * and consumption add up to its quantity; a lot taken back by this
     * reversal was never paired and holds nothing.
     */
    private function state(string $id, object $lot, VolumeEntry $reversal, string $quantity, string $remaining, string $consumed): BinaryReversalLotState
    {
        if ($lot->reversed_by_volume_entry_id !== null) {
            if ($lot->reversed_by_volume_entry_id !== $reversal->getKey()) {
                throw InvalidBinaryCorrection::corruptLot($id, "it was taken back by [{$lot->reversed_by_volume_entry_id}], not by this reversal");
            }

            if ($remaining !== '0' || $consumed !== '0') {
                throw InvalidBinaryCorrection::corruptLot($id, "it was taken back by this reversal, yet holds {$remaining} and had {$consumed} millionths paired");
            }

            return BinaryReversalLotState::AlreadyRemoved;
        }

        if (PairingArithmetic::add($remaining, $consumed) !== $quantity) {
            throw InvalidBinaryCorrection::corruptLot($id, "its remainder {$remaining} and pairings {$consumed} do not add up to its {$quantity} millionths");
        }

        return match (true) {
            $consumed === '0' => BinaryReversalLotState::Unconsumed,
            $remaining === '0' => BinaryReversalLotState::FullyConsumed,
            default => BinaryReversalLotState::PartiallyConsumed,
        };
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, object>
     */
    private function byId(Connection $db, string $table, array $ids): array
    {
        $rows = [];

        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
            foreach ($db->table($table)->whereIn('id', $chunk)->get() as $row) {
                $rows[(string) $row->id] = $row;
            }
        }

        return $rows;
    }
}
