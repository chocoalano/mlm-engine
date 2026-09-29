<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Correction;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use PandaBear\Mlm\Binary\Pairing\PairingArithmetic;
use PandaBear\Mlm\Exceptions\InvalidCommissionAdjustment;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Models\BinaryPairingCorrection;
use PandaBear\Mlm\Models\BinaryPairingResult;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Volume\Quantity;

/**
 * How much of each binary commission one reversal takes back (ADR-026) —
 * from what is stored, and nothing else. Read-only.
 *
 * The commission's stored amount is the financial truth: no strategy runs
 * again — no pair count, pair quantity, amount per pair, unit amount or
 * rounding mode, and no genealogy. The stored pairing result says how much
 * quantity the pairing consumed (Q); the correction journal (ADR-025) says
 * how much of it each reversal undid. A pair undone on one side releases the
 * same quantity on the other, so everything undone never exceeds Q.
 *
 * The share is cumulative: the reversals that undid part of one pairing are
 * ordered by their moment, then their id, and the target after each is
 * ⌊A × I ÷ Q⌋ of everything undone through it — so a reversal's correction
 * is its target less the one before it. Rounding each correction on its
 * own would drift; this never does: the corrections never add up to more
 * than A, and once everything is undone they add up to A exactly. The order
 * depends only on the reversals, so a correction comes out the same
 * whichever reversal is processed first. Floor is the allocation's own
 * rule, not the plan's rounding mode: it divides an amount already stored,
 * it does not award one. All exact, in decimal digits.
 */
final readonly class BinaryFinancialCorrectionAllocator
{
    /**
     * Each commission's share of the reversal's corrections.
     *
     * @param  iterable<BinaryPairingCorrection>  $corrections  the reversal's corrections of pairings that earned the commissions
     * @param  array<string, Commission>  $commissions  those commissions, by id
     * @return array<string, BinaryFinancialCorrectionShare> by commission id
     *
     * @throws InvalidCommissionAdjustment when the journal, the pairing results and the commissions do not agree
     */
    public function allocate(Connection $db, VolumeEntry $reversal, iterable $corrections, array $commissions): array
    {
        $reversalId = (string) $reversal->getKey();
        $ids = [];
        $results = [];

        foreach ($corrections as $correction) {
            $commission = (string) $correction->commission_id;
            $result = (string) $correction->binary_pairing_result_id;

            $problem = match (true) {
                $correction->reversal_volume_entry_id !== $reversalId => "correction [{$correction->getKey()}] belongs to another reversal",
                $correction->original_volume_entry_id !== $reversal->reversal_of_id => "correction [{$correction->getKey()}] undoes another entry than the one reversed",
                $correction->program_id !== $reversal->program_id => "correction [{$correction->getKey()}] belongs to another program",
                ! isset($commissions[$commission]) => "correction [{$correction->getKey()}] names commission [{$commission}], which was not given",
                ($results[$commission] ?? $result) !== $result => "commission [{$commission}] is named by corrections of two pairings",
                default => null,
            };

            if ($problem !== null) {
                throw InvalidCommissionAdjustment::binaryJournal($reversalId, $problem);
            }

            $results[$commission] = $result;
            $ids[$commission][] = (string) $correction->getKey();
        }

        $pairings = BinaryPairingResult::on($db->getName())
            ->whereIn('id', array_values($results))
            ->get(['id', 'program_id', 'plan_component_id', 'consumed_quantity', 'commission_id'])
            ->keyBy('id');

        $undone = $this->undone($db, array_values($results));
        $shares = [];

        foreach ($results as $commissionId => $resultId) {
            $pairing = $pairings->get($resultId);

            if ($pairing === null || $pairing->commission_id !== $commissionId) {
                throw InvalidCommissionAdjustment::binaryJournal($reversalId, "commission [{$commissionId}] was not earned by pairing result [{$resultId}]");
            }

            $correctionIds = $ids[$commissionId];
            sort($correctionIds, SORT_STRING);

            $shares[$commissionId] = $this->share($reversalId, $commissions[$commissionId], $pairing, $correctionIds, $undone[$resultId] ?? []);
        }

        ksort($shares, SORT_STRING);

        return $shares;
    }

    /**
     * @param  list<string>  $correctionIds
     * @param  list<array{reversal: string, at: string, quantity: string}>  $undone  every reversal's total for the pairing, in order
     */
    private function share(string $reversalId, Commission $commission, BinaryPairingResult $pairing, array $correctionIds, array $undone): BinaryFinancialCorrectionShare
    {
        $consumed = PairingArithmetic::millionths(Quantity::of($pairing->consumed_quantity));

        if ($consumed === '0') {
            throw InvalidCommissionAdjustment::binaryJournal($reversalId, "pairing result [{$pairing->getKey()}] earned commission [{$commission->getKey()}] but consumed nothing");
        }

        $before = '0';
        $after = null;

        foreach ($undone as $reversal) {
            if ($reversal['reversal'] === $reversalId) {
                $after = PairingArithmetic::add($before, $reversal['quantity']);

                break;
            }

            $before = PairingArithmetic::add($before, $reversal['quantity']);
        }

        if ($after === null || PairingArithmetic::compare($after, $consumed) > 0) {
            throw InvalidCommissionAdjustment::binaryJournal($reversalId, "pairing result [{$pairing->getKey()}] consumed {$pairing->consumed_quantity}, yet its corrections through this reversal undo more");
        }

        $amount = $commission->amount;

        if (! $amount->isPositive()) {
            throw InvalidCommissionAdjustment::binaryJournal($reversalId, "commission [{$commission->getKey()}] has no positive amount");
        }

        return new BinaryFinancialCorrectionShare(
            commissionId: (string) $commission->getKey(),
            pairingResultId: (string) $pairing->getKey(),
            planComponentId: (string) $pairing->plan_component_id,
            correctionIds: $correctionIds,
            consumedQuantity: $consumed,
            originalAmount: $amount,
            invalidatedBefore: $before,
            invalidatedAfter: $after,
            targetBefore: self::target($amount, $before, $consumed),
            targetAfter: self::target($amount, $after, $consumed),
        );
    }

    /**
     * ⌊A × I ÷ Q⌋, in financial millionths.
     */
    private static function target(FinancialAmount $amount, string $invalidated, string $consumed): FinancialAmount
    {
        return FinancialAmount::fromMillionths(PairingArithmetic::floorDivide(PairingArithmetic::multiply($amount->toMillionths(), $invalidated), $consumed));
    }

    /**
     * What each reversal undid of each pairing, summed over its
     * corrections: by pairing result, the reversals by their moment, then
     * their id. One read for all of them.
     *
     * @param  list<string>  $results
     * @return array<string, list<array{reversal: string, at: string, quantity: string}>>
     */
    private function undone(Connection $db, array $results): array
    {
        $rows = $db->table('mlm_binary_pairing_corrections as corrections')
            ->join('mlm_volume_entries as reversals', 'reversals.id', '=', 'corrections.reversal_volume_entry_id')
            ->whereIn('corrections.binary_pairing_result_id', $results)
            ->get(['corrections.binary_pairing_result_id as result', 'corrections.reversal_volume_entry_id as reversal', 'corrections.quantity_millionths as quantity', 'reversals.effective_at as at']);

        $undone = [];

        foreach ($rows as $row) {
            $entry = &$undone[(string) $row->result][(string) $row->reversal];
            $entry ??= ['reversal' => (string) $row->reversal, 'at' => CarbonImmutable::parse((string) $row->at)->format('Y-m-d H:i:s.u'), 'quantity' => '0'];
            $entry['quantity'] = PairingArithmetic::add($entry['quantity'], (string) $row->quantity);
            unset($entry);
        }

        return array_map(static function (array $reversals): array {
            usort($reversals, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']) ?: strcmp($a['reversal'], $b['reversal']));

            return $reversals;
        }, $undone);
    }
}
