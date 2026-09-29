<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PandaBear\Mlm\Exceptions\ImmutableFinancialRecord;
use PandaBear\Mlm\Finance\LedgerAccountManager;
use PandaBear\Mlm\Finance\WalletManager;

/**
 * Where postings land, in one program and one currency: a wallet's account
 * — `wallet_id` set, its key chosen by the wallet manager — or a system
 * account the program opened under a key of its own, `wallet_id` null.
 *
 * It has no balance column: its balance is always the sum of its postings
 * (`LedgerBalanceReader`). Read-only through Eloquent; `WalletManager` and
 * `LedgerAccountManager` open accounts, and nothing changes one afterwards.
 *
 * @property string $id
 * @property string $program_id
 * @property string|null $wallet_id
 * @property string $currency
 * @property string $key
 * @property-read Program $program
 * @property-read Wallet|null $wallet
 */
final class LedgerAccount extends MlmModel
{
    protected $table = 'mlm_ledger_accounts';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    public function isWalletAccount(): bool
    {
        return $this->wallet_id !== null;
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * The wallet this account belongs to; none for a system account.
     *
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw ImmutableFinancialRecord::outsideWriter(self::class, WalletManager::class.' and '.LedgerAccountManager::class);
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
