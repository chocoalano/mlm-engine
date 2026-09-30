<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Concerns;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Payout\PayoutBatchManager;
use PandaBear\Mlm\Payout\PayoutManager;

/**
 * Wallets funded through the ledger, and payout requests made the
 * supported way.
 *
 * Uses BuildsLedgers.
 */
trait BuildsPayouts
{
    protected function payouts(): PayoutManager
    {
        return $this->app->make(PayoutManager::class);
    }

    protected function payoutBatches(): PayoutBatchManager
    {
        return $this->app->make(PayoutBatchManager::class);
    }

    /**
     * The member's IDR wallet, credited `$amount` from the program's
     * clearing account.
     */
    protected function fundedWallet(Member $member, string $amount, string $key = 'fund:1'): Wallet
    {
        $account = $this->walletAccount($member);
        $clearing = $this->systemAccounts()->openSystemAccount($member->program, 'IDR', 'adjustment.clearing');
        $this->postLedger($member->program, [[$clearing, '-'.$amount], [$account, $amount]], $key, $key);

        return Wallet::query()->where('member_id', $member->id)->sole();
    }

    protected function settlementAccount(Member $member, string $currency = 'IDR', string $key = 'payout.settlement'): LedgerAccount
    {
        return $this->systemAccounts()->openSystemAccount($member->program, $currency, $key);
    }

    protected function payoutRequest(Member $member, string $amount, string $key = 'payout:1', ?LedgerAccount $settlement = null, string $at = '2026-03-01 10:00:00'): PayoutRequest
    {
        return $this->payouts()->request(
            member: $member,
            wallet: Wallet::query()->where('member_id', $member->id)->where('currency', 'IDR')->sole(),
            settlementAccount: $settlement ?? $this->settlementAccount($member),
            amount: $amount,
            destinationType: 'bank-account',
            destinationReference: 'dest:'.$member->member_code,
            requestedAt: CarbonImmutable::parse($at),
            idempotencyKey: $key,
        );
    }

    protected function walletBalance(Member $member): string
    {
        return $this->balances()->forWallet(Wallet::query()->where('member_id', $member->id)->where('currency', 'IDR')->sole())->value();
    }
}
