<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\InvalidLedgerAccount;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerPosting;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;

/**
 * A balance is derived, never stored: the exact sum of an account's
 * postings, reversals included, at any size, and it may be negative.
 */
final class LedgerBalanceReaderTest extends DatabaseTestCase
{
    use BuildsLedgers;

    private Program $program;

    private Wallet $wallet;

    private LedgerAccount $clearing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->program = Program::factory()->create();
        $this->wallet = $this->wallets()->open(Member::factory()->for($this->program)->create(), 'IDR');
        $this->clearing = $this->systemAccounts()->openSystemAccount($this->program, 'IDR', 'adjustment.clearing');
    }

    public function test_a_new_wallet_and_account_hold_zero(): void
    {
        $this->assertSame('0', $this->balances()->forWallet($this->wallet)->value());
        $this->assertSame('0', $this->balances()->forAccount($this->clearing)->value());
    }

    public function test_a_balance_is_the_sum_of_the_accounts_postings(): void
    {
        $this->credit('100.5', 'ADJ-1');
        $this->credit('0.25', 'ADJ-2');
        $this->credit('-50', 'ADJ-3');

        $this->assertSame('50.75', $this->balances()->forWallet($this->wallet)->value());
        $this->assertSame('-50.75', $this->balances()->forAccount($this->clearing)->value());
    }

    public function test_a_wallets_balance_is_its_accounts(): void
    {
        $this->credit('42.123456', 'ADJ-1');
        $account = $this->wallet->account;

        $this->assertNotNull($account);
        $this->assertTrue($this->balances()->forWallet($this->wallet)->equals($this->balances()->forAccount($account)));
    }

    public function test_a_reversal_nets_to_zero_as_the_postings_it_is(): void
    {
        $this->credit('75', 'ADJ-1');
        $this->reverseLedger($this->credit('100', 'ADJ-2'));

        $this->assertSame('75', $this->balances()->forWallet($this->wallet)->value());
        $this->assertSame('-75', $this->balances()->forAccount($this->clearing)->value());
    }

    public function test_a_wallet_balance_may_be_negative(): void
    {
        $this->credit('-30', 'ADJ-1');

        $this->assertSame('-30', $this->balances()->forWallet($this->wallet)->value());
        $this->assertSame('30', $this->balances()->forAccount($this->clearing)->value());
    }

    public function test_a_balance_beyond_64_bits_stays_exact(): void
    {
        // Three of the largest postings: 27,670,116,110,564,327,421
        // millionths, beyond any 64-bit integer — and beyond SQLite's SUM.
        foreach (['one', 'two', 'three'] as $source) {
            $this->postLedger($this->program, [
                [$this->systemAccounts()->openSystemAccount($this->program, 'IDR', "reserve.{$source}"), '-9223372036854.775807'],
                [$this->walletAccount($this->wallet->member), '9223372036854.775807'],
            ], "adjustment:{$source}", $source);
        }

        $balance = $this->balances()->forWallet($this->wallet);

        $this->assertSame('27670116110564.327421', $balance->value());
        $this->assertSame('27670116110564327421', $balance->toMillionths());
        $this->assertSame(1, $balance->compare(FinancialAmount::fromMillionths(PHP_INT_MAX)));
    }

    public function test_every_posting_counts_across_read_chunks(): void
    {
        // 2,500 postings to the wallet, more than two read chunks — written
        // in bulk for speed, balanced as the recorder writes them.
        $account = $this->wallet->account;
        $this->assertNotNull($account);
        $now = now()->format('Y-m-d H:i:s');
        [$transactions, $postings] = [[], []];

        for ($i = 1; $i <= 2500; $i++) {
            $transaction = (new LedgerTransaction)->newUniqueId();
            $transactions[] = ['id' => $transaction, 'program_id' => $this->program->id, 'currency' => 'IDR', 'type' => 'adjustment', 'source_type' => 'manual', 'source_id' => "B-{$i}", 'idempotency_key' => "bulk:{$i}", 'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            $postings[] = ['id' => (new LedgerPosting)->newUniqueId(), 'ledger_transaction_id' => $transaction, 'ledger_account_id' => $account->id, 'amount_millionths' => $i, 'created_at' => $now, 'updated_at' => $now];
            $postings[] = ['id' => (new LedgerPosting)->newUniqueId(), 'ledger_transaction_id' => $transaction, 'ledger_account_id' => $this->clearing->id, 'amount_millionths' => -$i, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($transactions, 500) as $chunk) {
            DB::table('mlm_ledger_transactions')->insert($chunk);
        }

        foreach (array_chunk($postings, 500) as $chunk) {
            DB::table('mlm_ledger_postings')->insert($chunk);
        }

        // 1 + 2 + … + 2500 millionths.
        $this->assertSame('3.12625', $this->balances()->forWallet($this->wallet)->value());
        $this->assertSame('-3.12625', $this->balances()->forAccount($this->clearing)->value());
    }

    public function test_reading_a_balance_writes_nothing(): void
    {
        $this->reverseLedger($this->credit('100', 'ADJ-1'));
        $before = $this->ledgerRows();

        $this->balances()->forWallet($this->wallet);
        $this->balances()->forAccount($this->clearing);
        $this->wallet->account?->wallet?->member?->wallets()->get();

        $this->assertSame($before, $this->ledgerRows());
    }

    public function test_a_wallet_without_its_account_is_reported_not_read_as_zero(): void
    {
        // Only a raw write removes a wallet's account.
        DB::table('mlm_ledger_accounts')->where('wallet_id', $this->wallet->id)->delete();

        $this->expectException(InvalidLedgerAccount::class);
        $this->expectExceptionMessage("Wallet [{$this->wallet->id}] has no ledger account");

        $this->balances()->forWallet($this->wallet);
    }

    private function credit(string $amount, string $source): LedgerTransaction
    {
        return $this->postLedger($this->program, [
            [$this->clearing, $amount[0] === '-' ? substr($amount, 1) : '-'.$amount],
            [$this->walletAccount($this->wallet->member), $amount],
        ], "adjustment:{$source}", $source);
    }
}
