<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\InvalidCurrencyCode;
use PandaBear\Mlm\Exceptions\InvalidLedgerAccount;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * System accounts: a program's own ledger accounts, identified by program,
 * currency and an exact key, opened once and never redefined. The package
 * opens none by itself.
 */
final class LedgerAccountManagerTest extends DatabaseTestCase
{
    use BuildsLedgers;

    public function test_a_system_account_is_opened_under_its_key(): void
    {
        $program = Program::factory()->create();

        $account = $this->systemAccounts()->openSystemAccount($program, 'IDR', 'commission.payable');

        $this->assertSame([$program->id, null, 'IDR', 'commission.payable'], [$account->program_id, $account->wallet_id, $account->currency, $account->key]);
        $this->assertFalse($account->isWalletAccount());
        $this->assertNull($account->wallet);
        $this->assertTrue($account->program->is($program));
    }

    public function test_opening_it_again_returns_it(): void
    {
        $program = Program::factory()->create();

        $first = $this->systemAccounts()->openSystemAccount($program, 'IDR', 'commission.payable');
        $again = $this->systemAccounts()->openSystemAccount($program, 'IDR', 'commission.payable');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, LedgerAccount::query()->count());
    }

    public function test_program_currency_and_key_each_make_another_account(): void
    {
        [$main, $other] = Program::factory()->count(2)->create()->all();

        $accounts = [
            $this->systemAccounts()->openSystemAccount($main, 'IDR', 'commission.payable')->id,
            $this->systemAccounts()->openSystemAccount($main, 'USD', 'commission.payable')->id,
            $this->systemAccounts()->openSystemAccount($main, 'IDR', 'payout.clearing')->id,
            $this->systemAccounts()->openSystemAccount($other, 'IDR', 'commission.payable')->id,
        ];

        $this->assertCount(4, array_unique($accounts));
    }

    public function test_the_package_opens_no_account_of_its_own(): void
    {
        Program::factory()->create();
        Member::factory()->create();

        $this->assertSame(0, LedgerAccount::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Commission.Payable'],
            'a space' => ['commission payable'],
            'a class name' => ['App\\Accounts\\Payable'],
            'a leading dot' => ['.payable'],
            'a trailing newline' => ["payable\n"],
            'too long' => [str_repeat('a', 101)],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function test_a_key_is_taken_exactly_or_refused(string $key): void
    {
        try {
            $this->systemAccounts()->openSystemAccount(Program::factory()->create(), 'IDR', $key);
            $this->fail('An invalid key opened an account.');
        } catch (InvalidLedgerAccount $exception) {
            $this->assertStringContainsString('1–100 lowercase letters', $exception->getMessage());
        }

        $this->assertSame(0, LedgerAccount::query()->count());
    }

    public function test_the_longest_key_is_accepted(): void
    {
        $account = $this->systemAccounts()->openSystemAccount(Program::factory()->create(), 'IDR', str_repeat('a', 100));

        $this->assertSame(str_repeat('a', 100), $account->key);
    }

    public function test_a_currency_is_taken_exactly(): void
    {
        $this->expectException(InvalidCurrencyCode::class);

        $this->systemAccounts()->openSystemAccount(Program::factory()->create(), 'idr', 'commission.payable');
    }

    public function test_wallet_account_keys_cannot_be_claimed(): void
    {
        $member = Member::factory()->create();
        $wallet = $this->wallets()->open($member, 'IDR');

        foreach (['wallet.'.$wallet->id, 'wallet.anything'] as $key) {
            try {
                $this->systemAccounts()->openSystemAccount($member->program, 'IDR', $key);
                $this->fail("\"{$key}\" opened a system account.");
            } catch (InvalidLedgerAccount $exception) {
                $this->assertStringContainsString('cannot start with "wallet."', $exception->getMessage());
            }
        }

        $this->assertSame(1, LedgerAccount::query()->count());
    }

    public function test_a_wallet_account_under_a_system_key_is_refused_not_returned(): void
    {
        // Only a raw write gives a wallet's account a key of this shape.
        $member = Member::factory()->create();
        $wallet = $this->wallets()->open($member, 'IDR');
        DB::table('mlm_ledger_accounts')->where('wallet_id', $wallet->id)->update(['key' => 'commission.payable']);

        $this->expectException(InvalidLedgerAccount::class);
        $this->expectExceptionMessage('belongs to a wallet; a system account cannot claim it');

        $this->systemAccounts()->openSystemAccount($member->program, 'IDR', 'commission.payable');
    }

    public function test_the_stored_program_decides_not_the_instance(): void
    {
        $program = Program::factory()->create(['code' => 'MAIN']);
        $program->code = 'CHANGED';

        $account = $this->systemAccounts()->openSystemAccount($program, 'IDR', 'commission.payable');

        $this->assertSame($program->id, $account->program_id);
        $this->assertSame('MAIN', Program::query()->findOrFail($program->id)->code);
    }
}
