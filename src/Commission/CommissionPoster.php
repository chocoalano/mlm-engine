<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use DateTimeInterface;
use Illuminate\Database\Connection;
use PandaBear\Mlm\Exceptions\InvalidCommissionPosting;
use PandaBear\Mlm\Exceptions\InvalidCommissionTransition;
use PandaBear\Mlm\Finance\FinanceInput;
use PandaBear\Mlm\Finance\LedgerPostingInput;
use PandaBear\Mlm\Finance\LedgerRecorder;
use PandaBear\Mlm\Finance\PostLedgerTransaction;
use PandaBear\Mlm\Finance\ReverseLedgerTransaction;
use PandaBear\Mlm\Finance\WalletManager;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;

/**
 * Moves a commission's money, through the ledger and nothing else.
 *
 * `post()` moves an APPROVED commission into its member's wallet: one
 * balanced ledger transaction — the run's source account debited, the
 * wallet's account credited, by the commission's amount — occurring when
 * the commission was earned. `reverse()` undoes a POSTED one with the
 * ledger's reversal, at a moment the caller gives.
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
        private WalletManager $wallets,
    ) {}

    /**
     * @throws InvalidCommissionTransition unless the commission is APPROVED, or already POSTED
     * @throws InvalidCommissionPosting for a POSTED commission whose ledger transaction does not match it
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

            [$source, $wallet] = $this->accounts($db, $current);

            $transaction = $this->ledger->post(new PostLedgerTransaction(
                program: Program::on($db->getName())->findOrFail($current->program_id),
                currency: $current->currency,
                type: self::TYPE,
                sourceType: self::SOURCE_TYPE,
                sourceId: $current->getKey(),
                idempotencyKey: self::postingKey($current),
                occurredAt: $current->earned_at,
                postings: [
                    LedgerPostingInput::of($source, $current->amount->negate()),
                    LedgerPostingInput::of($wallet, $current->amount),
                ],
            ));

            return CommissionStatusWriter::move($db, $current, CommissionStatus::Posted, $current->freshTimestamp(), [
                'ledger_transaction_id' => $transaction->getKey(),
            ]);
        });
    }

    /**
     * @throws InvalidCommissionTransition unless the commission is POSTED, or already REVERSED
     * @throws InvalidCommissionPosting for a REVERSED commission reversed at another moment
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

    public static function postingKey(Commission $commission): string
    {
        return 'commission.post.'.$commission->getKey();
    }

    public static function reversalKey(Commission $commission): string
    {
        return 'commission.reverse.'.$commission->getKey();
    }

    /**
     * The run's source account, and the account of the member's wallet in
     * the commission's currency — opened if need be.
     *
     * @return array{LedgerAccount, LedgerAccount}
     */
    private function accounts(Connection $db, Commission $commission): array
    {
        $run = CalculationRun::on($db->getName())->findOrFail($commission->calculation_run_id);
        $source = LedgerAccount::on($db->getName())->findOrFail($run->source_ledger_account_id);
        $wallet = $this->wallets->open(Member::on($db->getName())->findOrFail($commission->member_id), $commission->currency);
        $account = $wallet->relationLoaded('account') ? $wallet->account : null;

        return [$source, $account ?? LedgerAccount::on($db->getName())->where('wallet_id', $wallet->getKey())->firstOrFail()];
    }

    /**
     * The commission's ledger transaction, if it is exactly the one posting
     * it: the commission's own identity, source debited and wallet credited
     * by its amount.
     */
    private function assertPosted(Connection $db, Commission $commission): LedgerTransaction
    {
        $transaction = $commission->ledger_transaction_id === null
            ? null
            : LedgerTransaction::on($db->getName())->find($commission->ledger_transaction_id);

        if ($transaction === null) {
            throw InvalidCommissionPosting::inconsistent($commission, 'its ledger transaction is missing.');
        }

        [$source, $wallet] = $this->accounts($db, $commission);
        $expected = [
            (string) $source->getKey() => $commission->amount->negate()->toMillionths(),
            (string) $wallet->getKey() => $commission->amount->toMillionths(),
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
