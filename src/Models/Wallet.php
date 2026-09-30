<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use PandaBear\Mlm\Exceptions\ImmutableFinancialRecord;
use PandaBear\Mlm\Finance\WalletManager;

/**
 * A member's money in one currency: at most one per member and currency,
 * opened by `WalletManager` together with the one ledger account its
 * postings land in.
 *
 * It has no balance column: its balance is always the sum of its account's
 * postings (`LedgerBalanceReader`). Read-only through Eloquent — creating,
 * updating or deleting one through the model is refused. Raw query-builder
 * writes bypass this and are not a supported way to keep the ledger's
 * invariants.
 *
 * @property string $id
 * @property string $program_id
 * @property string $member_id
 * @property string $currency
 * @property-read Program $program
 * @property-read Member $member
 * @property-read LedgerAccount|null $account
 */
final class Wallet extends MlmModel
{
    protected $table = 'mlm_wallets';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * The one ledger account the wallet's postings land in.
     *
     * @return HasOne<LedgerAccount, $this>
     */
    public function account(): HasOne
    {
        return $this->hasOne(LedgerAccount::class);
    }

    /**
     * Every posting to the wallet's account — its ledger history. Read-only,
     * like the postings themselves.
     *
     * @return HasManyThrough<LedgerPosting, LedgerAccount, $this>
     */
    public function ledgerPostings(): HasManyThrough
    {
        return $this->hasManyThrough(LedgerPosting::class, LedgerAccount::class, 'wallet_id', 'ledger_account_id');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw ImmutableFinancialRecord::outsideWriter(self::class, WalletManager::class);
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
