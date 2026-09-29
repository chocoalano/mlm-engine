<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use Illuminate\Database\Connection;
use PandaBear\Mlm\Finance\WalletManager;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Member;

/**
 * @internal
 *
 * The two accounts a commission's money moves between: its run's source
 * account, and the account of its member's wallet in its currency — opened
 * if need be.
 */
final readonly class CommissionAccounts
{
    public function __construct(private WalletManager $wallets) {}

    /**
     * @return array{LedgerAccount, LedgerAccount} the source account, then the wallet's
     */
    public function of(Connection $db, Commission $commission): array
    {
        $run = CalculationRun::on($db->getName())->findOrFail($commission->calculation_run_id);
        $source = LedgerAccount::on($db->getName())->findOrFail($run->source_ledger_account_id);
        $wallet = $this->wallets->open(Member::on($db->getName())->findOrFail($commission->member_id), $commission->currency);
        $account = $wallet->relationLoaded('account') ? $wallet->account : null;

        return [$source, $account ?? LedgerAccount::on($db->getName())->where('wallet_id', $wallet->getKey())->firstOrFail()];
    }
}
