<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Payout;

use Illuminate\Database\Connection;
use PandaBear\Mlm\Exceptions\CorruptPayoutRequest;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\Wallet;

/**
 * @internal
 *
 * What a payout request's stored state must be (ADR-030), checked against
 * the database rather than trusted from its ids: its wallet the member's,
 * in its program and currency, with exactly one account; its settlement
 * account a system account of that program and currency; and exactly the
 * ledger transactions its status implies — a reservation moving exactly
 * its amount from the wallet's account to the settlement account while it
 * holds one, and a refund reversing exactly that reservation once it
 * failed. Anything else is `CorruptPayoutRequest`.
 */
final class PayoutLedger
{
    public const RESERVATION = 'payout-reservation';

    public const SOURCE_TYPE = 'payout-request';

    public static function reservationKey(string $request): string
    {
        return "payout.reserve.{$request}";
    }

    public static function refundKey(string $request): string
    {
        return "payout.refund.{$request}";
    }

    /**
     * The request checked in full; its wallet's account and its settlement
     * account.
     *
     * @return array{LedgerAccount, LedgerAccount}
     */
    public static function verify(Connection $db, PayoutRequest $request): array
    {
        [$wallet, $settlement] = self::accounts($db, $request);
        $status = $request->status;
        $problem = match (true) {
            $status === PayoutRequestStatus::Requested && $request->reservation_ledger_transaction_id !== null => 'it has a reservation',
            $status === PayoutRequestStatus::Cancelled && ($request->reservation_ledger_transaction_id !== null || $request->cancelled_at === null) => 'it has a reservation, or no cancellation moment',
            $status->holdsReservation() && $request->reservation_ledger_transaction_id === null => 'it has no reservation',
            $status === PayoutRequestStatus::Failed && ($request->reservation_ledger_transaction_id === null || $request->refund_ledger_transaction_id === null || $request->failed_at === null) => 'it has no reservation, refund or failure moment',
            $status !== PayoutRequestStatus::Failed && $request->refund_ledger_transaction_id !== null => 'it has a refund',
            $status === PayoutRequestStatus::Settled && ($request->settlement_reference === null || $request->settled_at === null) => 'it has no settlement reference or moment',
            $status !== PayoutRequestStatus::Settled && $request->settlement_reference !== null => 'it has a settlement reference',
            default => null,
        };

        if ($problem !== null) {
            throw CorruptPayoutRequest::because($request, $problem);
        }

        if ($request->reservation_ledger_transaction_id !== null) {
            $reservation = self::reservation($db, $request, $wallet, $settlement);

            if ($request->refund_ledger_transaction_id !== null) {
                self::refund($db, $request, $reservation, $wallet, $settlement);
            }
        }

        return [$wallet, $settlement];
    }

    /**
     * The wallet's one account and the settlement account, once the
     * member, wallet and account agree with the request and each other.
     *
     * @return array{LedgerAccount, LedgerAccount}
     */
    public static function accounts(Connection $db, PayoutRequest $request): array
    {
        $connection = (string) $db->getName();
        $wallet = Wallet::on($connection)->find($request->wallet_id);
        $member = Member::on($connection)->find($request->member_id);

        if ($wallet === null || $member === null || $member->program_id !== $request->program_id || $wallet->program_id !== $request->program_id || $wallet->member_id !== $request->member_id || $wallet->currency !== $request->currency) {
            throw CorruptPayoutRequest::because($request, 'its wallet is not its member\'s, in its program and currency');
        }

        $accounts = LedgerAccount::on($connection)->where('wallet_id', $wallet->getKey())->get();
        $settlement = LedgerAccount::on($connection)->find($request->settlement_ledger_account_id);

        if ($accounts->count() !== 1 || $accounts->first()->program_id !== $request->program_id || $accounts->first()->currency !== $request->currency) {
            throw CorruptPayoutRequest::because($request, 'its wallet does not have exactly one account in its program and currency');
        }

        if ($settlement === null || $settlement->program_id !== $request->program_id || $settlement->currency !== $request->currency || $settlement->wallet_id !== null) {
            throw CorruptPayoutRequest::because($request, 'its settlement account is not a system account of its program and currency');
        }

        return [$accounts->first(), $settlement];
    }

    /**
     * The reservation: the request's own identity, the wallet's account
     * debited and the settlement account credited by exactly its amount.
     */
    public static function reservation(Connection $db, PayoutRequest $request, LedgerAccount $wallet, LedgerAccount $settlement): LedgerTransaction
    {
        $transaction = LedgerTransaction::on((string) $db->getName())->find($request->reservation_ledger_transaction_id);
        $amount = $request->amount;

        $matches = $transaction !== null
            && $transaction->program_id === $request->program_id
            && $transaction->currency === $request->currency
            && $transaction->type === self::RESERVATION
            && $transaction->source_type === self::SOURCE_TYPE
            && $transaction->source_id === $request->getKey()
            && $transaction->idempotency_key === self::reservationKey((string) $request->getKey())
            && $transaction->reversal_of_id === null
            && self::postings($db, $transaction) === self::sorted([
                (string) $wallet->getKey() => $amount->negate()->toMillionths(),
                (string) $settlement->getKey() => $amount->toMillionths(),
            ]);

        if (! $matches) {
            throw CorruptPayoutRequest::because($request, "its reservation [{$request->reservation_ledger_transaction_id}] does not move exactly its amount from its wallet to its settlement account");
        }

        return $transaction;
    }

    /**
     * The refund: the ledger's reversal of exactly that reservation, under
     * the request's refund identity.
     */
    public static function refund(Connection $db, PayoutRequest $request, LedgerTransaction $reservation, LedgerAccount $wallet, LedgerAccount $settlement): LedgerTransaction
    {
        $transaction = LedgerTransaction::on((string) $db->getName())->find($request->refund_ledger_transaction_id);
        $amount = $request->amount;

        $matches = $transaction !== null
            && $transaction->reversal_of_id === $reservation->getKey()
            && $transaction->source_type === self::SOURCE_TYPE
            && $transaction->source_id === $request->getKey()
            && $transaction->idempotency_key === self::refundKey((string) $request->getKey())
            && self::postings($db, $transaction) === self::sorted([
                (string) $wallet->getKey() => $amount->toMillionths(),
                (string) $settlement->getKey() => $amount->negate()->toMillionths(),
            ]);

        if (! $matches) {
            throw CorruptPayoutRequest::because($request, "its refund [{$request->refund_ledger_transaction_id}] does not reverse exactly its reservation");
        }

        return $transaction;
    }

    /**
     * @return array<string, string> millionths by account, by account id
     */
    private static function postings(Connection $db, LedgerTransaction $transaction): array
    {
        return self::sorted($db->table('mlm_ledger_postings')
            ->where('ledger_transaction_id', $transaction->getKey())
            ->pluck('amount_millionths', 'ledger_account_id')
            ->map(static fn (mixed $amount): string => (string) $amount)
            ->all());
    }

    /**
     * @param  array<string, string>  $postings
     * @return array<string, string>
     */
    private static function sorted(array $postings): array
    {
        ksort($postings, SORT_STRING);

        return $postings;
    }
}
