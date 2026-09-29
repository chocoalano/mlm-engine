<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use Illuminate\Database\Connection;
use LogicException;
use PandaBear\Mlm\Exceptions\InvalidCommissionAdjustment;
use PandaBear\Mlm\Exceptions\InvalidCommissionPosting;
use PandaBear\Mlm\Exceptions\InvalidCommissionTransition;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionAdjustment;
use PandaBear\Mlm\Models\LedgerPosting;
use PandaBear\Mlm\Models\VolumeEntry;

/**
 * Corrects commissions whose source was later reversed (ADR-021) — the
 * only supported way to write commission adjustments.
 *
 * `processVolumeReversal()` finds every commission whose provenance names
 * the reversed original entry, and claws each back by exactly its stored
 * amount: nothing is recalculated — no strategy, genealogy, conversion or
 * rounding is run again, and the commission's calculated facts never
 * change. What happens depends on where the commission is:
 *
 * - CALCULATED, PENDING or APPROVED: no money has moved, so it is cancelled
 *   through `CommissionLifecycle` — outcome `cancelled`;
 * - POSTED: its ledger transaction is reversed through `CommissionPoster`,
 *   at the reversal's moment — outcome `reversed`;
 * - CANCELLED or REVERSED already: nothing changes — `already_cancelled`,
 *   or `already_reversed` once the existing ledger reversal is checked.
 *
 * Each commission gets one adjustment per reversal, recorded with the
 * negated amount whatever the outcome. A call applies every commission it
 * finds in one transaction — the reversal, its original, then the
 * commissions in id order are locked — or none. It is safe to call again:
 * adjusted commissions are returned as they were adjusted, and commissions
 * a calculation has created since — a historical run whose cutoff preceded
 * the reversal — are found and adjusted then. Nothing marks a reversal as
 * done for good.
 *
 * Volume history does not call this: the application decides when to
 * correct commissions after reversing volume.
 */
final readonly class CommissionAdjustmentEngine
{
    public const CLAWBACK = 'clawback';

    public const VOLUME_REVERSAL = 'volume-entry-reversal';

    public function __construct(
        private CommissionLifecycle $lifecycle,
        private CommissionPoster $poster,
    ) {}

    /**
     * @throws InvalidCommissionAdjustment for a request that is not a stored reversal, or stored data that does not agree
     * @throws InvalidCommissionPosting for a posted or reversed commission whose ledger state does not match it
     */
    public function processVolumeReversal(VolumeEntry $reversal): CommissionAdjustmentResult
    {
        $db = $reversal->getConnection();

        return $db->transaction(function () use ($db, $reversal): CommissionAdjustmentResult {
            [$reversal, $original] = $this->entries($db, (string) $reversal->getKey());

            // Found by provenance, never by reading traces, and whatever
            // program they claim — so a crossing is refused, not skipped.
            $commissions = Commission::on($db->getName())
                ->where('source_type', CommissionSourceReference::VOLUME_ENTRY)
                ->where('source_id', $original->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($commissions as $commission) {
                if ($commission->program_id !== $original->program_id) {
                    throw InvalidCommissionAdjustment::otherProgram($commission->getKey(), $commission->program_id, $original->getKey(), $original->program_id);
                }
            }

            $made = $commissions->isEmpty() ? collect() : CommissionAdjustment::on($db->getName())
                ->whereIn('commission_id', $commissions->modelKeys())
                ->where('type', self::CLAWBACK)
                ->where('source_type', self::VOLUME_REVERSAL)
                ->where('source_id', $reversal->getKey())
                ->get()
                ->keyBy('commission_id');

            $adjustments = [];

            foreach ($commissions as $commission) {
                $adjustments[] = $made->get($commission->getKey()) ?? $this->clawBack($db, $commission, $original, $reversal);
            }

            return new CommissionAdjustmentResult((string) $original->getKey(), (string) $reversal->getKey(), $adjustments);
        });
    }

    /**
     * The stored reversal and the original it reverses, locked in that
     * order, and held to the volume history's own rules.
     *
     * @return array{VolumeEntry, VolumeEntry}
     */
    private function entries(Connection $db, string $reversalId): array
    {
        $reversal = VolumeEntry::on($db->getName())->whereKey($reversalId)->lockForUpdate()->first()
            ?? throw InvalidCommissionAdjustment::missing('volume entry', $reversalId);

        if ($reversal->reversal_of_id === null) {
            throw InvalidCommissionAdjustment::notAReversal($reversalId);
        }

        $original = VolumeEntry::on($db->getName())->whereKey($reversal->reversal_of_id)->lockForUpdate()->first()
            ?? throw InvalidCommissionAdjustment::missing('reversed volume entry', $reversal->reversal_of_id);

        $problem = match (true) {
            $original->reversal_of_id !== null => 'the entry it names is itself a reversal',
            $original->program_id !== $reversal->program_id => 'they belong to different programs',
            $original->member_id !== $reversal->member_id => 'they belong to different members',
            $original->type !== $reversal->type => 'they are of different volume types',
            ! $reversal->quantity->equals($original->quantity->negate()) => 'its quantity is not exactly the original\'s, negated',
            default => null,
        };

        if ($problem !== null) {
            throw InvalidCommissionAdjustment::inconsistentReversal($reversalId, (string) $original->getKey(), $problem);
        }

        return [$reversal, $original];
    }

    private function clawBack(Connection $db, Commission $commission, VolumeEntry $original, VolumeEntry $reversal): CommissionAdjustment
    {
        $before = $commission->status;

        [$outcome, $ledgerTransaction] = match ($before) {
            CommissionStatus::Calculated, CommissionStatus::Pending, CommissionStatus::Approved => $this->cancel($commission),
            CommissionStatus::Posted => [CommissionAdjustmentOutcome::Reversed, $this->poster->reverse($commission, $reversal->effective_at)->reversal_ledger_transaction_id],
            CommissionStatus::Cancelled => [CommissionAdjustmentOutcome::AlreadyCancelled, null],
            CommissionStatus::Reversed => [CommissionAdjustmentOutcome::AlreadyReversed, (string) $this->poster->verifiedReversal($commission)->getKey()],
        };

        // The correction is the commission as stored, negated: never
        // recalculated, and within one posting as the commission was.
        $amount = $commission->amount->negate();

        if ($amount->negate()->compare(FinancialAmount::fromMillionths(LedgerPosting::MAX_MILLIONTHS)) > 0) {
            throw new LogicException("Commission [{$commission->getKey()}] holds more than one posting can.");
        }

        $id = (new CommissionAdjustment)->newUniqueId();
        $now = (new CommissionAdjustment)->freshTimestamp();

        $db->table('mlm_commission_adjustments')->insert([
            'id' => $id,
            'program_id' => $commission->program_id,
            'commission_id' => $commission->getKey(),
            'type' => self::CLAWBACK,
            'source_type' => self::VOLUME_REVERSAL,
            'source_id' => $reversal->getKey(),
            'amount_millionths' => $amount->toMillionths(),
            'occurred_at' => $reversal->effective_at,
            'outcome' => $outcome->value,
            'ledger_transaction_id' => $ledgerTransaction,
            'trace' => CommissionTrace::encode([
                'reason' => 'source-reversal',
                'source' => [
                    'original_volume_entry_id' => (string) $original->getKey(),
                    'reversal_volume_entry_id' => (string) $reversal->getKey(),
                    'reversal_effective_at' => $reversal->effective_at->format('Y-m-d H:i:s'),
                ],
                'commission' => [
                    'id' => (string) $commission->getKey(),
                    'calculation_run_id' => $commission->calculation_run_id,
                    'candidate_key' => $commission->candidate_key,
                    'member_id' => $commission->member_id,
                    'amount' => $commission->amount->value(),
                    'status_before' => $before->value,
                ],
                'adjustment' => [
                    'amount' => $amount->value(),
                    'outcome' => $outcome->value,
                    'ledger_transaction_id' => $ledgerTransaction,
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return CommissionAdjustment::on($db->getName())->findOrFail($id);
    }

    /**
     * @return array{CommissionAdjustmentOutcome, null}
     *
     * @throws InvalidCommissionTransition
     */
    private function cancel(Commission $commission): array
    {
        $this->lifecycle->cancel($commission);

        return [CommissionAdjustmentOutcome::Cancelled, null];
    }
}
