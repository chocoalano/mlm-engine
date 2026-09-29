<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Finance;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Wallet;

/**
 * Opens members' wallets — the only supported way to create one.
 *
 * A wallet is one member's money in one currency, and is opened together
 * with the one ledger account its postings land in: both, in one
 * transaction, or neither. Opening an open wallet returns it, so `open()` can
 * be called whenever a wallet is needed. The unique key on (program_id,
 * member_id, currency) is the concurrency backstop: a session that loses the
 * race to open the same wallet returns the winner's.
 */
final readonly class WalletManager
{
    private const WALLETS = 'mlm_wallets';

    private const ACCOUNTS = 'mlm_ledger_accounts';

    public function open(Member $member, CurrencyCode|string $currency): Wallet
    {
        $currency = CurrencyCode::from($currency)->value();

        // The program comes from the stored member, never from the instance
        // passed in.
        $member = $member->newQuery()->findOrFail($member->getKey());
        $db = $member->getConnection();

        $existing = $this->find($db, $member, $currency);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->create($db, $member, $currency);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->find($db, $member, $currency, lock: true) ?? throw $exception;
        }
    }

    /**
     * The key of a wallet's account: fixed by the wallet's id, never by
     * anything that can change.
     */
    public static function accountKey(string $walletId): string
    {
        return 'wallet.'.strtolower($walletId);
    }

    private function create(Connection $db, Member $member, string $currency): Wallet
    {
        $id = (new Wallet)->newUniqueId();
        $now = (new Wallet)->freshTimestamp();

        // Its own transaction — a savepoint inside the caller's, if there is
        // one — so the wallet never exists without its account, and a lost
        // race rolls back this write alone.
        $db->transaction(static function () use ($db, $member, $currency, $id, $now): void {
            $db->table(self::WALLETS)->insert([
                'id' => $id,
                'program_id' => $member->program_id,
                'member_id' => $member->getKey(),
                'currency' => $currency,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $db->table(self::ACCOUNTS)->insert([
                'id' => (new LedgerAccount)->newUniqueId(),
                'program_id' => $member->program_id,
                'wallet_id' => $id,
                'currency' => $currency,
                'key' => self::accountKey($id),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return Wallet::on($db->getName())->findOrFail($id);
    }

    /**
     * After a lost race, `$lock` makes the reads locking ones, which see the
     * winner's rows: a plain read inside a caller's transaction on MySQL sees
     * the snapshot that transaction took before they were committed. The
     * account is read with the wallet, for the same reason.
     */
    private function find(Connection $db, Member $member, string $currency, bool $lock = false): ?Wallet
    {
        $wallet = Wallet::on($db->getName())
            ->where('program_id', $member->program_id)
            ->where('member_id', $member->getKey())
            ->where('currency', $currency)
            ->when($lock, static fn (Builder $query): Builder => $query->sharedLock())
            ->first();

        if ($wallet !== null && $lock) {
            $wallet->setRelation('account', LedgerAccount::on($db->getName())->where('wallet_id', $wallet->getKey())->sharedLock()->first());
        }

        return $wallet;
    }
}
