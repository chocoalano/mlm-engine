<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Payout;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use PandaBear\Mlm\Exceptions\ConflictingPayoutRequest;
use PandaBear\Mlm\Exceptions\CorruptPayoutRequest;
use PandaBear\Mlm\Exceptions\InsufficientPayoutBalance;
use PandaBear\Mlm\Exceptions\InvalidFinancialAmount;
use PandaBear\Mlm\Exceptions\InvalidPayoutRequest;
use PandaBear\Mlm\Exceptions\InvalidPayoutTransition;
use PandaBear\Mlm\Finance\FinanceInput;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Finance\LedgerBalanceReader;
use PandaBear\Mlm\Finance\LedgerPostingInput;
use PandaBear\Mlm\Finance\LedgerRecorder;
use PandaBear\Mlm\Finance\PostLedgerTransaction;
use PandaBear\Mlm\Finance\ReverseLedgerTransaction;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerPosting;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\Wallet;

/**
 * Pays wallet value out (ADR-030) — the only way payout requests are
 * written. The wallet's ledger balance is what a payout spends: it never
 * reads commissions, periods, runs or genealogy, and no commission is ever
 * marked paid.
 *
 * - `request()` records a request: no money moves, and the balance is not
 *   checked yet.
 * - `approve()` reserves it: in one transaction, under the wallet's lock,
 *   the balance is read again and the amount moved from the wallet's
 *   account to the program's settlement account — so two approvals never
 *   spend the same funds, and the wallet's balance is what remains
 *   spendable.
 * - `startProcessing()` marks the external transfer begun; `settle()`
 *   records its external reference — neither moves money.
 * - `fail()` reverses the reservation: the amount returns to the wallet.
 * - `cancel()` withdraws a request before approval.
 *
 * Every step locks the request first, then — for approval — the wallet;
 * each is replayable, and a replay with other facts is refused. A later
 * commission correction may take the wallet below zero; payout history is
 * never rewritten.
 */
final readonly class PayoutManager
{
    private const TABLE = 'mlm_payout_requests';

    public function __construct(
        private LedgerRecorder $ledger,
        private LedgerBalanceReader $balances,
    ) {}

    /**
     * @throws InvalidPayoutRequest
     * @throws ConflictingPayoutRequest
     */
    public function request(
        Member $member,
        Wallet $wallet,
        LedgerAccount $settlementAccount,
        FinancialAmount|string|int $amount,
        string $destinationType,
        string $destinationReference,
        DateTimeInterface $requestedAt,
        string $idempotencyKey,
    ): PayoutRequest {
        try {
            $amount = $amount instanceof FinancialAmount ? $amount : FinancialAmount::of($amount);
        } catch (InvalidFinancialAmount $exception) {
            throw InvalidPayoutRequest::because($exception->getMessage());
        }

        if (! $amount->isPositive() || $amount->compare(FinancialAmount::fromMillionths(LedgerPosting::MAX_MILLIONTHS)) > 0) {
            throw InvalidPayoutRequest::because("an amount is strictly positive and at most one ledger posting; {$amount->value()} given.");
        }

        if (! FinanceInput::isIdentifier($destinationType, FinanceInput::IDENTIFIER_LENGTH)) {
            throw InvalidPayoutRequest::because('a destination type is 1 to 64 lowercase letters, digits, ".", "-" or "_"; '.var_export($destinationType, true).' given.');
        }

        foreach (['destination reference' => $destinationReference, 'idempotency key' => $idempotencyKey] as $field => $value) {
            if (! FinanceInput::isText($value, FinanceInput::IDEMPOTENCY_KEY_LENGTH)) {
                throw InvalidPayoutRequest::because("a {$field} is 1 to 191 printable characters; ".var_export($value, true).' given.');
            }
        }

        $requestedAt = FinanceInput::moment($requestedAt);
        $db = $member->getConnection();
        $connection = (string) $db->getName();

        return $db->transaction(static function () use ($db, $connection, $member, $wallet, $settlementAccount, $amount, $destinationType, $destinationReference, $requestedAt, $idempotencyKey): PayoutRequest {
            $member = Member::on($connection)->findOrFail($member->getKey());

            // The program's lock: two requests under one key never both land.
            Program::on($connection)->whereKey($member->program_id)->lockForUpdate()->firstOrFail();

            $wallet = Wallet::on($connection)->find($wallet->getKey()) ?? throw InvalidPayoutRequest::because("wallet [{$wallet->getKey()}] does not exist.");
            $facts = [
                'member' => (string) $member->getKey(),
                'wallet' => (string) $wallet->getKey(),
                'settlement account' => (string) $settlementAccount->getKey(),
                'currency' => $wallet->currency,
                'amount' => $amount->toMillionths(),
                'destination type' => $destinationType,
                'destination reference' => $destinationReference,
                'requested at' => $requestedAt->format('Y-m-d H:i:s'),
            ];

            $existing = PayoutRequest::on($connection)->where('program_id', $member->program_id)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($existing !== null) {
                $conflicts = array_keys(array_diff_assoc($facts, [
                    'member' => $existing->member_id,
                    'wallet' => $existing->wallet_id,
                    'settlement account' => $existing->settlement_ledger_account_id,
                    'currency' => $existing->currency,
                    'amount' => (string) $existing->amount_millionths,
                    'destination type' => $existing->destination_type,
                    'destination reference' => $existing->destination_reference,
                    'requested at' => $existing->requested_at->format('Y-m-d H:i:s'),
                ]));

                if ($conflicts !== []) {
                    throw ConflictingPayoutRequest::forKey($existing, $conflicts);
                }

                return $existing;
            }

            $problem = match (true) {
                $wallet->program_id !== $member->program_id => "wallet [{$wallet->getKey()}] belongs to another program.",
                $wallet->member_id !== $member->getKey() => "wallet [{$wallet->getKey()}] belongs to another member.",
                default => null,
            };

            if ($problem !== null) {
                throw InvalidPayoutRequest::because($problem);
            }

            $walletAccounts = LedgerAccount::on($connection)->where('wallet_id', $wallet->getKey())->get();
            $settlement = LedgerAccount::on($connection)->find($settlementAccount->getKey());

            $problem = match (true) {
                $walletAccounts->count() !== 1 || $walletAccounts->first()->currency !== $wallet->currency || $walletAccounts->first()->program_id !== $wallet->program_id => "wallet [{$wallet->getKey()}] does not have exactly one account in its program and currency.",
                $settlement === null => "settlement account [{$settlementAccount->getKey()}] does not exist.",
                $settlement->program_id !== $member->program_id => "settlement account [{$settlement->getKey()}] belongs to another program.",
                $settlement->currency !== $wallet->currency => "settlement account [{$settlement->getKey()}] is in {$settlement->currency}, the wallet in {$wallet->currency}.",
                $settlement->wallet_id !== null => "settlement account [{$settlement->getKey()}] is a wallet's account, not a system account.",
                default => null,
            };

            if ($problem !== null) {
                throw InvalidPayoutRequest::because($problem);
            }

            $request = new PayoutRequest;
            $id = $request->newUniqueId();
            $now = $request->freshTimestamp();

            $db->table(self::TABLE)->insert([
                'id' => $id,
                'program_id' => $member->program_id,
                'member_id' => $member->getKey(),
                'wallet_id' => $wallet->getKey(),
                'settlement_ledger_account_id' => $settlement->getKey(),
                'currency' => $wallet->currency,
                'amount_millionths' => $amount->toMillionths(),
                'destination_type' => $destinationType,
                'destination_reference' => $destinationReference,
                'idempotency_key' => $idempotencyKey,
                'status' => PayoutRequestStatus::Requested->value,
                'requested_at' => $requestedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return PayoutRequest::on($connection)->findOrFail($id);
        });
    }

    /**
     * Withdraws a request before approval. No money moves.
     *
     * @throws InvalidPayoutTransition
     * @throws ConflictingPayoutRequest
     */
    public function cancel(PayoutRequest $request, DateTimeInterface $at, ?string $reason = null): PayoutRequest
    {
        $at = FinanceInput::moment($at);
        self::assertReason($reason);

        return $this->step($request, function (Connection $db, PayoutRequest $current) use ($at, $reason): PayoutRequest {
            if ($current->status === PayoutRequestStatus::Cancelled) {
                return $this->replayed($db, $current, ['cancelled at' => [$current->cancelled_at, $at], 'reason' => [$current->failure_reason, $reason]]);
            }

            return $this->move($db, $current, PayoutRequestStatus::Cancelled, ['cancelled_at' => $at, 'failure_reason' => $reason]);
        });
    }

    /**
     * Reserves the request's amount: the wallet's account to the
     * settlement account, now, if the wallet holds it.
     *
     * @throws InsufficientPayoutBalance
     * @throws InvalidPayoutTransition
     * @throws CorruptPayoutRequest
     */
    public function approve(PayoutRequest $request, DateTimeInterface $at): PayoutRequest
    {
        $at = FinanceInput::moment($at);

        return $this->step($request, function (Connection $db, PayoutRequest $current) use ($at): PayoutRequest {
            if ($current->status !== PayoutRequestStatus::Requested) {
                if ($current->status === PayoutRequestStatus::Cancelled) {
                    throw InvalidPayoutTransition::from($current, PayoutRequestStatus::Approved);
                }

                PayoutLedger::verify($db, $current);

                return $current;
            }

            // The wallet's lock: approvals of one wallet run one at a time, and
            // the balance below is read once every earlier one has committed.
            Wallet::on((string) $db->getName())->whereKey($current->wallet_id)->lockForUpdate()->first();

            [$wallet, $settlement] = PayoutLedger::verify($db, $current);
            $balance = $this->balances->forAccount($wallet);

            if ($balance->compare($current->amount) < 0) {
                throw InsufficientPayoutBalance::forRequest($current, $balance->value());
            }

            $reservation = $this->ledger->post(new PostLedgerTransaction(
                program: Program::on((string) $db->getName())->findOrFail($current->program_id),
                currency: $current->currency,
                type: PayoutLedger::RESERVATION,
                sourceType: PayoutLedger::SOURCE_TYPE,
                sourceId: $current->getKey(),
                idempotencyKey: PayoutLedger::reservationKey((string) $current->getKey()),
                occurredAt: $at,
                postings: [
                    LedgerPostingInput::of($wallet, $current->amount->negate()),
                    LedgerPostingInput::of($settlement, $current->amount),
                ],
            ));

            return $this->move($db, $current, PayoutRequestStatus::Approved, ['approved_at' => $at, 'reservation_ledger_transaction_id' => $reservation->getKey()]);
        });
    }

    /**
     * Marks the external transfer of an approved request begun. A request
     * of a batch starts with its batch.
     *
     * @throws InvalidPayoutTransition
     */
    public function startProcessing(PayoutRequest $request, DateTimeInterface $at): PayoutRequest
    {
        $at = FinanceInput::moment($at);

        return $this->step($request, function (Connection $db, PayoutRequest $current) use ($at): PayoutRequest {
            if ($current->status === PayoutRequestStatus::Processing) {
                PayoutLedger::verify($db, $current);

                return $current;
            }

            if ($current->status !== PayoutRequestStatus::Approved) {
                throw InvalidPayoutTransition::from($current, PayoutRequestStatus::Processing);
            }

            PayoutLedger::verify($db, $current);

            $batch = $db->table('mlm_payout_batch_items as items')
                ->join('mlm_payout_batches as batches', 'batches.id', '=', 'items.payout_batch_id')
                ->where('items.payout_request_id', $current->getKey())
                ->first(['batches.id', 'batches.status']);

            if ($batch !== null && in_array($batch->status, [PayoutBatchStatus::Open->value, PayoutBatchStatus::Sealed->value], true)) {
                throw InvalidPayoutTransition::batched($current, (string) $batch->id, (string) $batch->status);
            }

            return $this->move($db, $current, PayoutRequestStatus::Processing, ['processing_at' => $at]);
        });
    }

    /**
     * Records the external settlement of a request in processing. No money
     * moves: it moved when the request was approved.
     *
     * @throws InvalidPayoutTransition
     * @throws ConflictingPayoutRequest
     */
    public function settle(PayoutRequest $request, string $settlementReference, DateTimeInterface $at): PayoutRequest
    {
        if (! FinanceInput::isText($settlementReference, FinanceInput::IDEMPOTENCY_KEY_LENGTH)) {
            throw InvalidPayoutRequest::because('a settlement reference is 1 to 191 printable characters; '.var_export($settlementReference, true).' given.');
        }

        $at = FinanceInput::moment($at);

        return $this->step($request, function (Connection $db, PayoutRequest $current) use ($settlementReference, $at): PayoutRequest {
            if ($current->status === PayoutRequestStatus::Settled) {
                return $this->replayed($db, $current, ['settlement reference' => [$current->settlement_reference, $settlementReference], 'settled at' => [$current->settled_at, $at]]);
            }

            if ($current->status !== PayoutRequestStatus::Processing) {
                throw InvalidPayoutTransition::from($current, PayoutRequestStatus::Settled);
            }

            PayoutLedger::verify($db, $current);

            $other = $db->table(self::TABLE)->where('program_id', $current->program_id)->where('settlement_reference', $settlementReference)->value('id');

            if ($other !== null) {
                throw ConflictingPayoutRequest::settlementReference($settlementReference, (string) $other);
            }

            try {
                return $this->move($db, $current, PayoutRequestStatus::Settled, ['settled_at' => $at, 'settlement_reference' => $settlementReference]);
            } catch (UniqueConstraintViolationException) {
                // Another request took the reference first.
                throw ConflictingPayoutRequest::settlementReference($settlementReference, 'another request');
            }
        });
    }

    /**
     * Fails an approved or processing request: its reservation is reversed,
     * and the amount returns to the wallet.
     *
     * @throws InvalidPayoutTransition
     * @throws ConflictingPayoutRequest
     * @throws CorruptPayoutRequest
     */
    public function fail(PayoutRequest $request, string $reason, DateTimeInterface $at): PayoutRequest
    {
        self::assertReason($reason);
        $at = FinanceInput::moment($at);

        return $this->step($request, function (Connection $db, PayoutRequest $current) use ($reason, $at): PayoutRequest {
            if ($current->status === PayoutRequestStatus::Failed) {
                return $this->replayed($db, $current, ['reason' => [$current->failure_reason, $reason], 'failed at' => [$current->failed_at, $at]]);
            }

            if (! in_array($current->status, [PayoutRequestStatus::Approved, PayoutRequestStatus::Processing], true)) {
                throw InvalidPayoutTransition::from($current, PayoutRequestStatus::Failed);
            }

            [$wallet, $settlement] = PayoutLedger::verify($db, $current);
            $reservation = PayoutLedger::reservation($db, $current, $wallet, $settlement);

            $refund = $this->ledger->reverse(new ReverseLedgerTransaction(
                transaction: $reservation,
                sourceType: PayoutLedger::SOURCE_TYPE,
                sourceId: $current->getKey(),
                idempotencyKey: PayoutLedger::refundKey((string) $current->getKey()),
                occurredAt: $at,
            ));

            return $this->move($db, $current, PayoutRequestStatus::Failed, ['failed_at' => $at, 'failure_reason' => $reason, 'refund_ledger_transaction_id' => $refund->getKey()]);
        });
    }

    /**
     * One transaction, the stored request locked before anything else is
     * read in it.
     *
     * @param  callable(Connection, PayoutRequest): PayoutRequest  $step
     */
    private function step(PayoutRequest $request, callable $step): PayoutRequest
    {
        $db = $request->getConnection();

        return $db->transaction(static function () use ($db, $request, $step): PayoutRequest {
            $current = PayoutRequest::on((string) $db->getName())->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            return $step($db, $current);
        });
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function move(Connection $db, PayoutRequest $current, PayoutRequestStatus $to, array $columns): PayoutRequest
    {
        if (! $current->status->canTransitionTo($to)) {
            throw InvalidPayoutTransition::from($current, $to);
        }

        $moved = $db->table(self::TABLE)->where('id', $current->getKey())->where('status', $current->status->value)->update([
            'status' => $to->value,
            ...$columns,
            'updated_at' => $current->freshTimestamp(),
        ]);

        if ($moved !== 1) {
            throw new LogicException("Payout request [{$current->getKey()}] changed while it was locked.");
        }

        return PayoutRequest::on((string) $db->getName())->findOrFail($current->getKey());
    }

    /**
     * A terminal step asked for again: the same facts return the request,
     * checked; other facts are refused.
     *
     * @param  array<string, array{mixed, mixed}>  $facts  stored and requested, by name
     */
    private function replayed(Connection $db, PayoutRequest $current, array $facts): PayoutRequest
    {
        $conflicts = [];

        foreach ($facts as $name => [$stored, $requested]) {
            $normalise = static fn (mixed $value): mixed => $value instanceof CarbonImmutable ? $value->format('Y-m-d H:i:s') : $value;

            if ($normalise($stored) !== $normalise($requested)) {
                $conflicts[] = $name;
            }
        }

        if ($conflicts !== []) {
            throw ConflictingPayoutRequest::step($current, $conflicts);
        }

        PayoutLedger::verify($db, $current);

        return $current;
    }

    private static function assertReason(?string $reason): void
    {
        if ($reason !== null && ! FinanceInput::isText($reason, 255)) {
            throw InvalidPayoutRequest::because('a reason is 1 to 255 printable characters; '.var_export($reason, true).' given.');
        }
    }
}
