<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\ImmutableFinancialRecord;
use PandaBear\Mlm\Exceptions\InvalidCurrencyCode;
use PandaBear\Mlm\Finance\CurrencyCode;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * A wallet is one member's money in one currency, opened once, together with
 * its one ledger account — and it holds no balance of its own.
 */
final class WalletManagerTest extends DatabaseTestCase
{
    use BuildsLedgers;

    public function test_opening_a_wallet_opens_its_one_account(): void
    {
        $member = Member::factory()->create();

        $wallet = $this->wallets()->open($member, 'IDR');
        $account = LedgerAccount::query()->sole();

        $this->assertSame([$member->program_id, $member->id, 'IDR'], [$wallet->program_id, $wallet->member_id, $wallet->currency]);
        $this->assertSame([$member->program_id, $wallet->id, 'IDR', 'wallet.'.$wallet->id], [$account->program_id, $account->wallet_id, $account->currency, $account->key]);
        $this->assertTrue($account->isWalletAccount());
        $this->assertTrue($account->is($wallet->account));
        $this->assertSame(1, Wallet::query()->count());
    }

    public function test_opening_an_open_wallet_returns_it(): void
    {
        $member = Member::factory()->create();

        $first = $this->wallets()->open($member, 'IDR');
        $again = $this->wallets()->open($member, CurrencyCode::of('IDR'));

        $this->assertSame($first->id, $again->id);
        $this->assertSame([1, 1], [Wallet::query()->count(), LedgerAccount::query()->count()]);
    }

    public function test_each_member_and_currency_has_its_own_wallet(): void
    {
        $program = Program::factory()->create();
        [$alice, $bob] = Member::factory()->for($program)->count(2)->create()->all();

        $wallets = [
            $this->wallets()->open($alice, 'IDR')->id,
            $this->wallets()->open($alice, 'USD')->id,
            $this->wallets()->open($bob, 'IDR')->id,
        ];

        $this->assertCount(3, array_unique($wallets));
        $this->assertSame(3, LedgerAccount::query()->whereIn('wallet_id', $wallets)->distinct()->count('wallet_id'));
        $this->assertEqualsCanonicalizing(['IDR', 'USD'], $alice->wallets()->pluck('currency')->all());
        $this->assertSame(3, $program->wallets()->count());
    }

    public function test_a_currency_is_taken_exactly_as_given(): void
    {
        $member = Member::factory()->create();

        foreach (['idr', 'Idr', ' IDR', 'RUPIAH'] as $currency) {
            try {
                $this->wallets()->open($member, $currency);
                $this->fail("\"{$currency}\" opened a wallet.");
            } catch (InvalidCurrencyCode) {
            }
        }

        $this->assertSame(0, Wallet::query()->count());
    }

    public function test_the_members_stored_program_decides_the_wallets(): void
    {
        $member = Member::factory()->create();
        $stored = $member->program_id;

        $member->program_id = Program::factory()->create()->id;
        $wallet = $this->wallets()->open($member, 'IDR');

        $this->assertSame($stored, $wallet->program_id);
        $this->assertSame($stored, $wallet->account?->program_id);
    }

    public function test_a_wallet_is_never_left_without_its_account(): void
    {
        $member = Member::factory()->create();
        $this->failInsertsInto('mlm_ledger_accounts');

        try {
            $this->wallets()->open($member, 'IDR');
            $this->fail('The wallet opened although its account could not be written.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The disk is full.', $exception->getMessage());
        }

        $this->assertSame([0, 0], [DB::table('mlm_wallets')->count(), DB::table('mlm_ledger_accounts')->count()]);
    }

    public function test_a_wallet_opens_inside_a_callers_transaction(): void
    {
        $member = Member::factory()->create();

        $wallet = DB::transaction(fn (): Wallet => $this->wallets()->open($member, 'IDR'));

        $this->assertTrue($wallet->is($this->wallets()->open($member, 'IDR')));
        $this->assertSame(1, LedgerAccount::query()->count());
    }

    public function test_the_relations_read_the_wallet_and_its_owners(): void
    {
        $member = Member::factory()->create();
        $wallet = $this->wallets()->open($member, 'IDR');

        $this->assertTrue($wallet->member->is($member));
        $this->assertTrue($wallet->program->is($member->program));
        $this->assertTrue($wallet->account?->wallet?->is($wallet));
        $this->assertTrue($wallet->account->program->is($member->program));
        $this->assertTrue($member->wallets()->sole()->is($wallet));
        $this->assertTrue($member->program->wallets()->sole()->is($wallet));
    }

    /**
     * @return array<string, array{Closure(Wallet): mixed}>
     */
    public static function modelWrites(): array
    {
        return [
            'creating a wallet' => [static fn (Wallet $wallet): mixed => (new Wallet)->forceFill(['program_id' => $wallet->program_id, 'member_id' => $wallet->member_id, 'currency' => 'USD'])->save()],
            'changing a wallet' => [static fn (Wallet $wallet): mixed => $wallet->forceFill(['currency' => 'USD'])->save()],
            'deleting a wallet' => [static fn (Wallet $wallet): mixed => $wallet->delete()],
            'creating an account' => [static fn (Wallet $wallet): mixed => (new LedgerAccount)->forceFill(['program_id' => $wallet->program_id, 'currency' => 'IDR', 'key' => 'cash'])->save()],
            'changing an account' => [static fn (Wallet $wallet): mixed => $wallet->account?->forceFill(['key' => 'renamed'])->save()],
            'deleting an account' => [static fn (Wallet $wallet): mixed => $wallet->account?->delete()],
        ];
    }

    /**
     * @param  Closure(Wallet): mixed  $write
     */
    #[DataProvider('modelWrites')]
    public function test_wallets_and_accounts_are_read_only_through_eloquent(Closure $write): void
    {
        $wallet = $this->wallets()->open(Member::factory()->create(), 'IDR');
        $before = $this->ledgerRows();

        try {
            $write($wallet);
            $this->fail('A financial row was written through its model.');
        } catch (ImmutableFinancialRecord $exception) {
            $this->assertStringContainsString('never changed', $exception->getMessage());
        }

        $this->assertSame($before, $this->ledgerRows());
    }

    public function test_a_member_with_a_wallet_cannot_be_deleted(): void
    {
        $member = Member::factory()->create();
        $this->wallets()->open($member, 'IDR');

        $this->expectException(QueryException::class);

        DB::table('mlm_members')->where('id', $member->id)->delete();
    }
}
