<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Finance\LedgerAccountManager;
use PandaBear\Mlm\Finance\LedgerBalanceReader;
use PandaBear\Mlm\Finance\LedgerPostingInput;
use PandaBear\Mlm\Finance\LedgerRecorder;
use PandaBear\Mlm\Finance\PostLedgerTransaction;
use PandaBear\Mlm\Finance\ReverseLedgerTransaction;
use PandaBear\Mlm\Finance\WalletManager;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use RuntimeException;

/**
 * Wallets, accounts and transactions built the supported way — through the
 * managers and the recorder — and the ledger's rows read back as plain data.
 */
trait BuildsLedgers
{
    protected function wallets(): WalletManager
    {
        return $this->app->make(WalletManager::class);
    }

    protected function systemAccounts(): LedgerAccountManager
    {
        return $this->app->make(LedgerAccountManager::class);
    }

    protected function ledger(): LedgerRecorder
    {
        return $this->app->make(LedgerRecorder::class);
    }

    protected function balances(): LedgerBalanceReader
    {
        return $this->app->make(LedgerBalanceReader::class);
    }

    /**
     * The ledger account of the member's wallet in this currency, opening it
     * if need be.
     */
    protected function walletAccount(Member $member, string $currency = 'IDR'): LedgerAccount
    {
        return LedgerAccount::query()->where('wallet_id', $this->wallets()->open($member, $currency)->id)->sole();
    }

    /**
     * @param  list<array{LedgerAccount, string}>  $lines  account and signed amount
     */
    protected function postCommand(
        Program $program,
        array $lines,
        string $key = 'adjustment:ADJ-1',
        string $currency = 'IDR',
        string $type = 'adjustment',
        string $sourceType = 'manual',
        string $sourceId = 'ADJ-1',
        string $at = '2026-06-01 12:00:00',
    ): PostLedgerTransaction {
        return new PostLedgerTransaction(
            program: $program,
            currency: $currency,
            type: $type,
            sourceType: $sourceType,
            sourceId: $sourceId,
            idempotencyKey: $key,
            occurredAt: CarbonImmutable::parse($at),
            postings: array_map(static fn (array $line): LedgerPostingInput => LedgerPostingInput::of($line[0], $line[1]), $lines),
        );
    }

    /**
     * @param  list<array{LedgerAccount, string}>  $lines
     */
    protected function postLedger(Program $program, array $lines, string $key = 'adjustment:ADJ-1', string $sourceId = 'ADJ-1', string $at = '2026-06-01 12:00:00'): LedgerTransaction
    {
        return $this->ledger()->post($this->postCommand($program, $lines, $key, sourceId: $sourceId, at: $at));
    }

    protected function reverseCommand(LedgerTransaction $transaction, string $key = 'reversal:REV-1', string $sourceType = 'manual', string $sourceId = 'REV-1', string $at = '2026-06-15 12:00:00'): ReverseLedgerTransaction
    {
        return new ReverseLedgerTransaction(
            transaction: $transaction,
            sourceType: $sourceType,
            sourceId: $sourceId,
            idempotencyKey: $key,
            occurredAt: CarbonImmutable::parse($at),
        );
    }

    protected function reverseLedger(LedgerTransaction $transaction, string $key = 'reversal:REV-1', string $sourceId = 'REV-1'): LedgerTransaction
    {
        return $this->ledger()->reverse($this->reverseCommand($transaction, $key, sourceId: $sourceId));
    }

    /**
     * A transaction's postings as stored: amount by account id.
     *
     * @return array<string, string>
     */
    protected function postingsOf(LedgerTransaction $transaction): array
    {
        return $transaction->postings()->get()->mapWithKeys(
            static fn ($posting): array => [$posting->ledger_account_id => $posting->amount->value()],
        )->all();
    }

    /**
     * Makes every insert into the table fail as a database would, without
     * any hook in the package.
     */
    protected function failInsertsInto(string $table): void
    {
        DB::connection()->beforeExecuting(static function (string $query) use ($table): void {
            if (str_starts_with(strtolower($query), 'insert') && str_contains($query, $table)) {
                throw new RuntimeException('The disk is full.');
            }
        });
    }

    /**
     * Every row of the financial tables, in a stable order.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function ledgerRows(?string $connection = null): array
    {
        $rows = [];

        foreach (['mlm_wallets', 'mlm_ledger_accounts', 'mlm_ledger_transactions', 'mlm_ledger_postings'] as $table) {
            $rows[$table] = DB::connection($connection)->table($table)->orderBy('id')->get()
                ->map(static fn (object $row): array => array_map(static fn (mixed $value): mixed => is_int($value) ? (string) $value : $value, (array) $row))
                ->all();
        }

        return $rows;
    }
}
