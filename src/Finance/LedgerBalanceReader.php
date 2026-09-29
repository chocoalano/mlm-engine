<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Finance;

use PandaBear\Mlm\Exceptions\InvalidLedgerAccount;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Wallet;

/**
 * Balances, derived from the ledger itself: an account's balance is the
 * exact sum of every posting to it — reversals included, as the negated
 * postings they are — and a wallet's is its account's. Nothing is stored or
 * cached, and nothing is written.
 *
 * The postings are read in chunks and summed exactly in PHP, not by the
 * database: SQLite's integer SUM overflows past 64 bits, and a balance may be
 * larger than that. A balance read while transactions are being posted
 * includes every transaction committed before the read began, and may
 * include some committed during it.
 */
final class LedgerBalanceReader
{
    private const CHUNK = 1000;

    /**
     * May be negative: the ledger sets no floor. Whether a balance may be
     * spent below zero is a business rule for the domains that spend it.
     */
    public function forAccount(LedgerAccount $account): FinancialAmount
    {
        $account = $account->newQuery()->findOrFail($account->getKey());

        return FinancialAmount::sum(
            $account->getConnection()->table('mlm_ledger_postings')
                ->where('ledger_account_id', $account->getKey())
                ->select(['id', 'amount_millionths'])
                ->lazyById(self::CHUNK)
                ->map(static fn (object $posting): FinancialAmount => FinancialAmount::fromMillionths($posting->amount_millionths)),
        );
    }

    public function forWallet(Wallet $wallet): FinancialAmount
    {
        $account = LedgerAccount::on($wallet->getConnectionName())->where('wallet_id', $wallet->getKey())->first()
            ?? throw InvalidLedgerAccount::walletWithoutAccount((string) $wallet->getKey());

        return $this->forAccount($account);
    }
}
