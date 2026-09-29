<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Finance;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use PandaBear\Mlm\Exceptions\InvalidLedgerAccount;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Program;

/**
 * Opens a program's system accounts: ledger accounts that belong to no
 * member — what a later domain will need, such as commission payable. The
 * package opens none of its own; each domain opens the accounts it names.
 *
 * A system account is identified by its program, currency and key, and is
 * never redefined: opening it again returns it. Keys starting with "wallet."
 * name wallet accounts and cannot be claimed.
 */
final readonly class LedgerAccountManager
{
    private const ACCOUNTS = 'mlm_ledger_accounts';

    /**
     * @param  string  $key  1–100 lowercase letters, digits, ".", "-" or "_", e.g. "commission.payable"
     */
    public function openSystemAccount(Program $program, CurrencyCode|string $currency, string $key): LedgerAccount
    {
        $currency = CurrencyCode::from($currency)->value();

        if (! FinanceInput::isIdentifier($key, FinanceInput::ACCOUNT_KEY_LENGTH)) {
            throw InvalidLedgerAccount::key($key);
        }

        if (str_starts_with($key, 'wallet.')) {
            throw InvalidLedgerAccount::reservedKey($key);
        }

        $program = $program->newQuery()->findOrFail($program->getKey());
        $db = $program->getConnection();

        $existing = $this->find($db, $program, $currency, $key);

        if ($existing !== null) {
            return $this->system($existing);
        }

        try {
            $id = (new LedgerAccount)->newUniqueId();
            $now = (new LedgerAccount)->freshTimestamp();

            $db->transaction(static fn (): bool => $db->table(self::ACCOUNTS)->insert([
                'id' => $id,
                'program_id' => $program->getKey(),
                'wallet_id' => null,
                'currency' => $currency,
                'key' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            return LedgerAccount::on($db->getName())->findOrFail($id);
        } catch (UniqueConstraintViolationException $exception) {
            // Lost a race to open the same account.
            return $this->system($this->find($db, $program, $currency, $key, lock: true) ?? throw $exception);
        }
    }

    private function system(LedgerAccount $account): LedgerAccount
    {
        if ($account->wallet_id !== null) {
            throw InvalidLedgerAccount::notASystemAccount($account->program_id, $account->currency, $account->key, $account->getKey());
        }

        return $account;
    }

    private function find(Connection $db, Program $program, string $currency, string $key, bool $lock = false): ?LedgerAccount
    {
        return LedgerAccount::on($db->getName())
            ->where('program_id', $program->getKey())
            ->where('currency', $currency)
            ->where('key', $key)
            ->when($lock, static fn (Builder $query): Builder => $query->sharedLock())
            ->first();
    }
}
