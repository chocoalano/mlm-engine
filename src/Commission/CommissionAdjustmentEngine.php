<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use LogicException;
use PandaBear\Mlm\Binary\Correction\BinaryFinancialCorrectionAllocator;
use PandaBear\Mlm\Binary\Correction\BinaryFinancialCorrectionShare;
use PandaBear\Mlm\Exceptions\InvalidCommissionAdjustment;
use PandaBear\Mlm\Exceptions\InvalidCommissionPosting;
use PandaBear\Mlm\Exceptions\InvalidCommissionTransition;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Models\BinaryPairingCorrection;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionAdjustment;
use PandaBear\Mlm\Models\LedgerPosting;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Volume\Quantity;

/**
 * Corrects commissions whose source was later reversed (ADR-021, ADR-026)
 * — the only supported way to write commission adjustments.
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
 * `processBinaryReversal()` corrects binary pairing commissions (ADR-026).
 * A binary commission has no single source: a reversal undoes part of its
 * pairing, as the correction journal records once a pairing run has taken
 * the reversal in (ADR-025). The part undone takes back the matching share
 * of the stored amount — `BinaryFinancialCorrectionAllocator` — never the
 * whole commission unless its whole pairing is undone, and nothing is
 * recalculated. Each commission gets one `clawback` adjustment per
 * reversal, of source `binary-volume-reversal`, recorded with its share
 * negated — zero when the share is under a financial millionth:
 *
 * - CALCULATED, PENDING or APPROVED: no money has moved. If the corrections
 *   leave nothing, it is cancelled — `cancelled`; otherwise it keeps its
 *   status and posting will move only what is left — `recorded`;
 * - POSTED: a zero share moves nothing — `recorded`. A share that leaves
 *   nothing, with no part of it moved back before, reverses the posting
 *   through `CommissionPoster` — `reversed`. Any other share moves back
 *   through a ledger transaction of its own, `CommissionAdjustmentPoster`,
 *   and the commission stays posted — `adjusted`;
 * - CANCELLED or REVERSED already: as for a source reversal.
 *
 * Volume history does not call either: the application decides when to
 * correct commissions after reversing volume — and, for binary commissions,
 * after the pairing run that takes the reversal in.
 */
final readonly class CommissionAdjustmentEngine
{
    public const CLAWBACK = 'clawback';

    public const VOLUME_REVERSAL = 'volume-entry-reversal';

    public const BINARY_VOLUME_REVERSAL = 'binary-volume-reversal';

    public function __construct(
        private CommissionLifecycle $lifecycle,
        private CommissionPoster $poster,
        private CommissionAdjustmentPoster $adjustmentPoster,
        private BinaryFinancialCorrectionAllocator $allocator,
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
     * Corrects every binary pairing commission whose pairing the reversal
     * partly or wholly undid, as far as pairing runs have recorded it so far
     * (ADR-026). Nothing is found until the run the reversal falls in has
     * committed; calling again then finds it.
     *
     * @throws InvalidCommissionAdjustment for a request that is not a stored reversal, or stored data that does not agree
     * @throws InvalidCommissionPosting for a posted or reversed commission whose ledger state does not match it
     */
    public function processBinaryReversal(VolumeEntry $reversal): CommissionAdjustmentResult
    {
        $db = $reversal->getConnection();

        return $db->transaction(function () use ($db, $reversal): CommissionAdjustmentResult {
            [$reversal, $original] = $this->entries($db, (string) $reversal->getKey());

            // Read under a lock, like everything read before the commissions
            // are: on MySQL the first plain read of a transaction fixes what
            // every later one sees, and they must see the adjustments of any
            // other reversal committed while this call waited for a
            // commission.
            $corrections = BinaryPairingCorrection::on($db->getName())
                ->where('reversal_volume_entry_id', $reversal->getKey())
                ->whereNotNull('commission_id')
                ->orderBy('id')
                ->sharedLock()
                ->get();

            $commissions = $corrections->isEmpty() ? collect() : Commission::on($db->getName())
                ->whereIn('id', $corrections->pluck('commission_id')->unique()->values()->all())
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
                ->where('source_type', self::BINARY_VOLUME_REVERSAL)
                ->where('source_id', $reversal->getKey())
                ->get()
                ->keyBy('commission_id');

            $pending = $commissions->reject(static fn (Commission $commission): bool => $made->has($commission->getKey()))->keyBy('id');
            $shares = [];
            $earlier = collect();

            if ($pending->isNotEmpty()) {
                $shares = $this->allocator->allocate($db, $reversal, $corrections->whereIn('commission_id', $pending->keys()->all())->values(), $pending->all());
                $earlier = CommissionAdjustment::on($db->getName())
                    ->whereIn('commission_id', $pending->keys()->all())
                    ->orderBy('id')
                    ->get()
                    ->groupBy('commission_id');
            }

            $adjustments = [];

            foreach ($commissions as $commission) {
                $adjustments[] = $made->get($commission->getKey())
                    ?? $this->correctBinary($db, $commission, $shares[$commission->getKey()], $earlier->get($commission->getKey(), collect()), $original, $reversal);
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
     * @param  Collection<int, CommissionAdjustment>  $earlier  every adjustment the commission already has
     */
    private function correctBinary(Connection $db, Commission $commission, BinaryFinancialCorrectionShare $share, Collection $earlier, VolumeEntry $original, VolumeEntry $reversal): CommissionAdjustment
    {
        $before = $commission->status;
        $net = CommissionNetAmount::from($commission, $earlier->map(static fn (CommissionAdjustment $adjustment): FinancialAmount => $adjustment->amount));
        $left = $net->add($share->amount->negate());

        if ($left->isNegative()) {
            throw InvalidCommissionAdjustment::netOutOfRange((string) $commission->getKey(), $commission->amount->value(), $left->value());
        }

        [$outcome, $ledgerTransaction] = match ($before) {
            CommissionStatus::Calculated, CommissionStatus::Pending, CommissionStatus::Approved => $left->isZero()
                ? $this->cancel($commission)
                : [CommissionAdjustmentOutcome::Recorded, null],
            CommissionStatus::Posted => $this->correctPosted($commission, $share->amount, $net, $left, $earlier, $reversal),
            CommissionStatus::Cancelled => [CommissionAdjustmentOutcome::AlreadyCancelled, null],
            CommissionStatus::Reversed => [CommissionAdjustmentOutcome::AlreadyReversed, (string) $this->poster->verifiedReversal($commission)->getKey()],
        };

        $amount = $share->amount->negate();
        $id = (new CommissionAdjustment)->newUniqueId();
        $now = (new CommissionAdjustment)->freshTimestamp();
        $quantity = static fn (string $millionths): string => Quantity::fromMillionths($millionths)->value();

        $db->table('mlm_commission_adjustments')->insert([
            'id' => $id,
            'program_id' => $commission->program_id,
            'commission_id' => $commission->getKey(),
            'type' => self::CLAWBACK,
            'source_type' => self::BINARY_VOLUME_REVERSAL,
            'source_id' => $reversal->getKey(),
            'amount_millionths' => $amount->toMillionths(),
            'occurred_at' => $reversal->effective_at,
            'outcome' => $outcome->value,
            'ledger_transaction_id' => $ledgerTransaction,
            'trace' => CommissionTrace::encode([
                'reason' => 'binary-source-reversal',
                'source' => [
                    'original_volume_entry_id' => (string) $original->getKey(),
                    'reversal_volume_entry_id' => (string) $reversal->getKey(),
                    'reversal_effective_at' => $reversal->effective_at->format('Y-m-d H:i:s'),
                ],
                'binary' => [
                    'plan_component_id' => $share->planComponentId,
                    'pairing_result_id' => $share->pairingResultId,
                    'correction_ids' => $share->correctionIds,
                    'total_consumed_quantity' => $quantity($share->consumedQuantity),
                    'invalidated_before' => $quantity($share->invalidatedBefore),
                    'invalidated_after' => $quantity($share->invalidatedAfter),
                ],
                'financial' => [
                    'original_commission_amount' => $share->originalAmount->value(),
                    'target_before' => $share->targetBefore->value(),
                    'target_after' => $share->targetAfter->value(),
                    'adjustment_amount' => $amount->value(),
                    'allocation_policy' => 'cumulative-floor',
                ],
                'commission' => [
                    'id' => (string) $commission->getKey(),
                    'member_id' => $commission->member_id,
                    'status_before' => $before->value,
                    'posted_amount' => $commission->postedAmount?->value(),
                ],
                'outcome' => $outcome->value,
                'ledger_transaction_id' => $ledgerTransaction,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return CommissionAdjustment::on($db->getName())->findOrFail($id);
    }

    /**
     * A posted commission's share: nothing moves for a zero share; a share
     * that leaves nothing reverses the posting, unless part of it already
     * went back — then, like any other share, it moves back on its own.
     *
     * @param  Collection<int, CommissionAdjustment>  $earlier
     * @return array{CommissionAdjustmentOutcome, string|null}
     */
    private function correctPosted(Commission $commission, FinancialAmount $amount, FinancialAmount $net, FinancialAmount $left, Collection $earlier, VolumeEntry $reversal): array
    {
        if ($amount->isZero()) {
            return [CommissionAdjustmentOutcome::Recorded, null];
        }

        // What the wallet still holds of it: what was posted, less what
        // corrections have moved back since. Always its net amount.
        $movedBack = $earlier->filter(static fn (CommissionAdjustment $adjustment): bool => $adjustment->outcome === CommissionAdjustmentOutcome::Adjusted);
        $held = ($commission->postedAmount ?? throw InvalidCommissionPosting::inconsistent($commission, 'it records no posted amount.'))
            ->add(FinancialAmount::sum($movedBack->map(static fn (CommissionAdjustment $adjustment): FinancialAmount => $adjustment->amount)));

        if (! $held->equals($net)) {
            throw InvalidCommissionPosting::inconsistent($commission, "its wallet still holds {$held} of it, not its net amount {$net}.");
        }

        if ($left->isZero() && $movedBack->isEmpty()) {
            return [CommissionAdjustmentOutcome::Reversed, $this->poster->reverse($commission, $reversal->effective_at)->reversal_ledger_transaction_id];
        }

        $transaction = $this->adjustmentPoster->post($commission, $amount, self::BINARY_VOLUME_REVERSAL, (string) $reversal->getKey(), $reversal->effective_at);

        return [CommissionAdjustmentOutcome::Adjusted, (string) $transaction->getKey()];
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
