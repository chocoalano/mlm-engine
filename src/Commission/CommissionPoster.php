<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use DateTimeInterface;
use Illuminate\Database\Connection;
use PandaBear\Mlm\Exceptions\InvalidCommissionAdjustment;
use PandaBear\Mlm\Exceptions\InvalidCommissionPosting;
use PandaBear\Mlm\Exceptions\InvalidCommissionTransition;
use PandaBear\Mlm\Exceptions\UnresolvedBinaryCorrection;
use PandaBear\Mlm\Finance\FinanceInput;
use PandaBear\Mlm\Finance\LedgerPostingInput;
use PandaBear\Mlm\Finance\LedgerRecorder;
use PandaBear\Mlm\Finance\PostLedgerTransaction;
use PandaBear\Mlm\Finance\ReverseLedgerTransaction;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Program;

/**
 * Moves a commission's money, through the ledger and nothing else.
 *
 * `post()` moves an APPROVED commission into its member's wallet: one
 * balanced ledger transaction — the run's source account debited, the
 * wallet's account credited — occurring when the commission was earned.
 * It moves the commission's net amount (ADR-026): what was calculated, less
 * the binary corrections recorded before posting — the whole amount when
 * there are none — and records it as the commission's `posted_amount`. A
 * commission whose binary pairing a reversal has partly undone is not
 * posted until that correction's financial share is recorded
 * (`UnresolvedBinaryCorrection`), and one with nothing left to post is not
 * posted at all. `reverse()` undoes a POSTED one with the ledger's reversal
 * — exactly what was posted — at a moment the caller gives.
 *
 * Each is one transaction with the status change: the commission row is
 * locked, the ledger written through `LedgerRecorder`, and the status moved,
 * all together or not at all. The ledger transactions are identified by the
 * commission itself, so a repeated call returns the commission as it stands
 * and never moves money twice.
 */
final readonly class CommissionPoster
{
    public const TYPE = 'commission';

    public const SOURCE_TYPE = 'commission';

    public function __construct(
        private LedgerRecorder $ledger,
        private CommissionAccounts $accounts,
        private CommissionNetAmount $net,
    ) {}

    /**
     * @throws InvalidCommissionTransition unless the commission is APPROVED, or already POSTED
     * @throws UnresolvedBinaryCorrection while a binary correction of it has no financial adjustment
     * @throws InvalidCommissionPosting for a POSTED commission whose ledger transaction does not match it, or an APPROVED one with nothing left to post
     * @throws InvalidCommissionAdjustment when its stored adjustments leave it out of range
     */
    public function post(Commission $commission): Commission
    {
        $db = $commission->getConnection();

        return $db->transaction(function () use ($db, $commission): Commission {
            $current = CommissionStatusWriter::lock($db, $commission);

            if ($current->status === CommissionStatus::Posted) {
                $this->assertPosted($db, $current);

                return $current;
            }

            if ($current->status !== CommissionStatus::Approved) {
                throw InvalidCommissionTransition::from($current, CommissionStatus::Posted);
            }

            $this->assertNoUnresolvedCorrection($db, $current);

            $amount = $this->net->of($current);

            if ($amount->isZero()) {
                throw InvalidCommissionPosting::nothingToPost($current);
            }

            [$source, $wallet] = $this->accounts->of($db, $current);

            $transaction = $this->ledger->post(new PostLedgerTransaction(
                program: Program::on($db->getName())->findOrFail($current->program_id),
                currency: $current->currency,
                type: self::TYPE,
                sourceType: self::SOURCE_TYPE,
                sourceId: $current->getKey(),
                idempotencyKey: self::postingKey($current),
                occurredAt: $current->earned_at,
                postings: [
                    LedgerPostingInput::of($source, $amount->negate()),
                    LedgerPostingInput::of($wallet, $amount),
                ],
            ));

            return CommissionStatusWriter::move($db, $current, CommissionStatus::Posted, $current->freshTimestamp(), [
                'ledger_transaction_id' => $transaction->getKey(),
                'posted_amount_millionths' => $amount->toMillionths(),
            ]);
        });
    }

    /**
     * @throws InvalidCommissionTransition unless the commission is POSTED, or already REVERSED
     * @throws InvalidCommissionPosting for a REVERSED commission reversed at another moment, or a POSTED one already partly corrected through the ledger
     */
    public function reverse(Commission $commission, DateTimeInterface $occurredAt): Commission
    {
        $db = $commission->getConnection();
        $occurredAt = FinanceInput::moment($occurredAt);

        return $db->transaction(function () use ($db, $commission, $occurredAt): Commission {
            $current = CommissionStatusWriter::lock($db, $commission);

            if ($current->status === CommissionStatus::Reversed) {
                $reversal = $this->reversalOf($db, $current);

                if ($reversal->occurred_at->format('Y-m-d H:i:s') !== $occurredAt->format('Y-m-d H:i:s')) {
                    throw InvalidCommissionPosting::reversedAtAnotherMoment($current, $reversal->occurred_at->format('Y-m-d H:i:s'), $occurredAt->format('Y-m-d H:i:s'));
                }

                return $current;
            }

            if ($current->status !== CommissionStatus::Posted) {
                throw InvalidCommissionTransition::from($current, CommissionStatus::Reversed);
            }

            $original = $this->assertPosted($db, $current);

            // Part of its money already went back through a correction of
            // its own: reversing the whole posting would take back more than
            // the member still holds of it.
            $partlyAdjusted = $db->table('mlm_commission_adjustments')
                ->where('commission_id', $current->getKey())
                ->where('outcome', CommissionAdjustmentOutcome::Adjusted->value)
                ->exists();

            if ($partlyAdjusted) {
                throw InvalidCommissionPosting::partlyAdjusted($current);
            }

            $reversal = $this->ledger->reverse(new ReverseLedgerTransaction(
                transaction: $original,
                sourceType: self::SOURCE_TYPE,
                sourceId: $current->getKey(),
                idempotencyKey: self::reversalKey($current),
                occurredAt: $occurredAt,
            ));

            return CommissionStatusWriter::move($db, $current, CommissionStatus::Reversed, $current->freshTimestamp(), [
                'reversal_ledger_transaction_id' => $reversal->getKey(),
            ]);
        });
    }

    /**
     * The ledger transaction that reversed a REVERSED commission, once it is
     * checked to reverse exactly the transaction that posted it.
     *
     * @throws InvalidCommissionTransition unless the commission is REVERSED
     * @throws InvalidCommissionPosting when its ledger transactions do not match it
     */
    public function verifiedReversal(Commission $commission): LedgerTransaction
    {
        $db = $commission->getConnection();

        if ($commission->status !== CommissionStatus::Reversed) {
            throw InvalidCommissionTransition::from($commission, CommissionStatus::Reversed);
        }

        $this->assertPosted($db, $commission);

        return $this->reversalOf($db, $commission);
    }

    public static function postingKey(Commission $commission): string
    {
        return 'commission.post.'.$commission->getKey();
    }

    public static function reversalKey(Commission $commission): string
    {
        return 'commission.reverse.'.$commission->getKey();
    }

    /**
     * Refuses an approved commission while a binary correction of its
     * pairing (ADR-025) has no financial adjustment: posting it would pay
     * what the pairing no longer earns.
     */
    private function assertNoUnresolvedCorrection(Connection $db, Commission $commission): void
    {
        $reversals = $db->table('mlm_binary_pairing_corrections')
            ->where('commission_id', $commission->getKey())
            ->distinct()
            ->pluck('reversal_volume_entry_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($reversals === []) {
            return;
        }

        $resolved = $db->table('mlm_commission_adjustments')
            ->where('commission_id', $commission->getKey())
            ->where('type', CommissionAdjustmentEngine::CLAWBACK)
            ->where('source_type', CommissionAdjustmentEngine::BINARY_VOLUME_REVERSAL)
            ->pluck('source_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $unresolved = array_values(array_diff($reversals, $resolved));

        if ($unresolved !== []) {
            sort($unresolved, SORT_STRING);

            throw UnresolvedBinaryCorrection::beforePosting($commission, $unresolved);
        }
    }

    /**
     * The commission's ledger transaction, if it is exactly the one posting
     * it: the commission's own identity, source debited and wallet credited
     * by the amount it records as posted.
     */
    private function assertPosted(Connection $db, Commission $commission): LedgerTransaction
    {
        $transaction = $commission->ledger_transaction_id === null
            ? null
            : LedgerTransaction::on($db->getName())->find($commission->ledger_transaction_id);

        if ($transaction === null) {
            throw InvalidCommissionPosting::inconsistent($commission, 'its ledger transaction is missing.');
        }

        $posted = $commission->postedAmount;

        if ($posted === null) {
            throw InvalidCommissionPosting::inconsistent($commission, 'it records no posted amount.');
        }

        [$source, $wallet] = $this->accounts->of($db, $commission);
        $expected = [
            (string) $source->getKey() => $posted->negate()->toMillionths(),
            (string) $wallet->getKey() => $posted->toMillionths(),
        ];
        ksort($expected, SORT_STRING);

        $postings = $db->table('mlm_ledger_postings')
            ->where('ledger_transaction_id', $transaction->getKey())
            ->orderBy('ledger_account_id')
            ->pluck('amount_millionths', 'ledger_account_id')
            ->map(static fn (mixed $amount): string => (string) $amount)
            ->all();

        $matches = $transaction->program_id === $commission->program_id
            && $transaction->currency === $commission->currency
            && $transaction->type === self::TYPE
            && $transaction->source_type === self::SOURCE_TYPE
            && $transaction->source_id === $commission->getKey()
            && $transaction->idempotency_key === self::postingKey($commission)
            && $transaction->reversal_of_id === null
            && $postings === $expected;

        if (! $matches) {
            throw InvalidCommissionPosting::inconsistent($commission, "its ledger transaction [{$transaction->getKey()}] does not post it.");
        }

        return $transaction;
    }

    private function reversalOf(Connection $db, Commission $commission): LedgerTransaction
    {
        $reversal = $commission->reversal_ledger_transaction_id === null
            ? null
            : LedgerTransaction::on($db->getName())->find($commission->reversal_ledger_transaction_id);

        if ($reversal === null || $reversal->reversal_of_id !== $commission->ledger_transaction_id || $reversal->idempotency_key !== self::reversalKey($commission)) {
            throw InvalidCommissionPosting::inconsistent($commission, 'its reversal ledger transaction is missing or does not reverse it.');
        }

        return $reversal;
    }
}
