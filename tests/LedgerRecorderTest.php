<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\ConflictingLedgerReplay;
use PandaBear\Mlm\Exceptions\ImmutableFinancialRecord;
use PandaBear\Mlm\Exceptions\InvalidLedgerTransaction;
use PandaBear\Mlm\Finance\LedgerPostingInput;
use PandaBear\Mlm\Finance\PostLedgerTransaction;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerPosting;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Posting: one balanced, single-currency transaction within one program,
 * written with all its postings or not at all, from stored accounts, and
 * recognised when replayed.
 */
final class LedgerRecorderTest extends DatabaseTestCase
{
    use BuildsLedgers;

    private Program $program;

    private LedgerAccount $clearing;

    private LedgerAccount $alice;

    private LedgerAccount $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->program = Program::factory()->create();
        $this->clearing = $this->systemAccounts()->openSystemAccount($this->program, 'IDR', 'adjustment.clearing');
        $this->alice = $this->walletAccount(Member::factory()->for($this->program)->create());
        $this->bob = $this->walletAccount(Member::factory()->for($this->program)->create());
    }

    public function test_a_balanced_transaction_is_posted_with_all_its_postings(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-02 08:00:00'));

        $transaction = $this->ledger()->post(new PostLedgerTransaction(
            program: $this->program,
            currency: 'IDR',
            type: 'adjustment',
            sourceType: 'manual',
            sourceId: 'ADJ-1',
            idempotencyKey: 'adjustment:ADJ-1',
            // Another timezone and a fraction of a second: stored as the same
            // instant in the application's timezone, to the second.
            occurredAt: CarbonImmutable::parse('2026-06-01 19:00:00.750', 'Asia/Jakarta'),
            postings: [
                LedgerPostingInput::of($this->clearing, '-150.25'),
                LedgerPostingInput::of($this->alice, '100'),
                LedgerPostingInput::of($this->bob, '50.25'),
            ],
        ));

        $this->assertSame(
            [$this->program->id, 'IDR', 'adjustment', 'manual', 'ADJ-1', 'adjustment:ADJ-1', '2026-06-01 12:00:00', null],
            [$transaction->program_id, $transaction->currency, $transaction->type, $transaction->source_type, $transaction->source_id, $transaction->idempotency_key, $transaction->occurred_at->format('Y-m-d H:i:s'), $transaction->reversal_of_id],
        );
        $this->assertSame($this->sorted([$this->clearing->id => '-150.25', $this->alice->id => '100', $this->bob->id => '50.25']), $this->postingsOf($transaction));
        $this->assertSame(array_keys($this->postingsOf($transaction)), $transaction->postings->pluck('ledger_account_id')->all());
        $this->assertTrue($transaction->program->is($this->program));

        // One write moment for every row of the transaction.
        $moments = DB::table('mlm_ledger_postings')->pluck('created_at')
            ->merge(DB::table('mlm_ledger_postings')->pluck('updated_at'))
            ->push(DB::table('mlm_ledger_transactions')->value('created_at'), DB::table('mlm_ledger_transactions')->value('updated_at'))
            ->map(static fn (mixed $moment): string => CarbonImmutable::parse((string) $moment)->format('Y-m-d H:i:s'))
            ->unique()->values()->all();
        $this->assertSame(['2026-06-02 08:00:00'], $moments);

        $posting = $transaction->postings->first();
        $this->assertInstanceOf(LedgerPosting::class, $posting);
        $this->assertTrue($posting->transaction->is($transaction));
        $this->assertSame($posting->ledger_account_id, $posting->account->id);
    }

    /**
     * @return array<string, array{Closure(self): PostLedgerTransaction, string}>
     */
    public static function invalidTransactions(): array
    {
        return [
            'one posting' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, [[$test->alice, '100']]),
                'at least two accounts; 1 posting(s) given',
            ],
            'no postings' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, []),
                'at least two accounts; 0 posting(s) given',
            ],
            'a zero posting' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, [[$test->clearing, '-100'], [$test->alice, '100'], [$test->bob, '0']]),
                'A posting of zero records nothing',
            ],
            'one account twice' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, [[$test->clearing, '-100'], [$test->alice, '60'], [$test->alice, '40']]),
                'appears in more than one posting',
            ],
            'unbalanced' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, [[$test->clearing, '-100'], [$test->alice, '100.000001']]),
                'must sum to exactly zero; they sum to 0.000001',
            ],
            'a posting too large to reverse' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, [[$test->clearing, '-9223372036854.775808'], [$test->alice, '9223372036854.775808']]),
                'One posting holds at most',
            ],
            'not a posting' => [
                static fn (self $test): PostLedgerTransaction => new PostLedgerTransaction($test->program, 'IDR', 'adjustment', 'manual', 'ADJ-1', 'adjustment:ADJ-1', CarbonImmutable::now(), [['account' => $test->alice->id, 'amount' => '100'], LedgerPostingInput::of($test->clearing, '-100')]),
                'Every posting must be a LedgerPostingInput; array given',
            ],
            'a type in capitals' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, [[$test->clearing, '-1'], [$test->alice, '1']], type: 'Adjustment'),
                'ledger type must be 1–64 lowercase letters',
            ],
            'a class name as source type' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, [[$test->clearing, '-1'], [$test->alice, '1']], sourceType: 'App\\Manual'),
                'ledger source type must be 1–64',
            ],
            'a type too long' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, [[$test->clearing, '-1'], [$test->alice, '1']], type: str_repeat('a', 65)),
                'ledger type must be 1–64',
            ],
            'no source id' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, [[$test->clearing, '-1'], [$test->alice, '1']], sourceId: ''),
                'ledger source id must be 1–128 characters',
            ],
            'a padded idempotency key' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, [[$test->clearing, '-1'], [$test->alice, '1']], key: ' adjustment:ADJ-1'),
                'ledger idempotency key must be 1–191 characters',
            ],
            'an idempotency key too long' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, [[$test->clearing, '-1'], [$test->alice, '1']], key: str_repeat('k', 192)),
                'ledger idempotency key must be 1–191 characters',
            ],
            'a lowercase currency' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, [[$test->clearing, '-1'], [$test->alice, '1']], currency: 'idr'),
                'three uppercase ASCII letters',
            ],
        ];
    }

    /**
     * @param  Closure(self): PostLedgerTransaction  $command
     */
    #[DataProvider('invalidTransactions')]
    public function test_a_transaction_that_is_not_one_balanced_movement_is_refused(Closure $command, string $reason): void
    {
        $before = $this->ledgerRows();

        try {
            $this->ledger()->post($command($this));
            $this->fail('An invalid transaction was posted.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        $this->assertSame($before, $this->ledgerRows());
    }

    public function test_an_account_of_another_program_is_refused(): void
    {
        $elsewhere = $this->systemAccounts()->openSystemAccount(Program::factory()->create(), 'IDR', 'adjustment.clearing');

        $this->assertRefused(
            $this->postCommand($this->program, [[$elsewhere, '-100'], [$this->alice, '100']]),
            "belongs to program [{$elsewhere->program_id}], not to the transaction's program [{$this->program->id}]",
        );
    }

    public function test_an_account_in_another_currency_is_refused(): void
    {
        $dollars = $this->walletAccount(Member::query()->findOrFail($this->alice->wallet?->member_id), 'USD');

        $this->assertRefused(
            $this->postCommand($this->program, [[$this->clearing, '-100'], [$dollars, '100']]),
            'holds USD, not the transaction\'s currency IDR',
        );
    }

    public function test_an_account_that_does_not_exist_is_refused(): void
    {
        $ghost = (new LedgerAccount)->forceFill(['id' => (new LedgerAccount)->newUniqueId(), 'program_id' => $this->program->id, 'currency' => 'IDR']);

        $this->assertRefused(
            $this->postCommand($this->program, [[$this->clearing, '-100'], [$ghost, '100']]),
            "Ledger account [{$ghost->id}] does not exist",
        );
    }

    public function test_the_stored_accounts_decide_not_the_instances(): void
    {
        // Claims in memory that the stored rows contradict change nothing...
        $this->alice->forceFill(['currency' => 'USD', 'program_id' => Program::factory()->create()->id, 'wallet_id' => null]);

        $transaction = $this->postLedger($this->program, [[$this->clearing, '-100'], [$this->alice, '100']]);

        $this->assertSame([$this->program->id, 'IDR'], [$transaction->program_id, $transaction->currency]);

        // ...and cannot bring another program's account in.
        $elsewhere = $this->systemAccounts()->openSystemAccount(Program::factory()->create(), 'IDR', 'adjustment.clearing');
        $elsewhere->forceFill(['program_id' => $this->program->id]);

        $this->assertRefused($this->postCommand($this->program, [[$elsewhere, '-5'], [$this->bob, '5']], key: 'adjustment:ADJ-2'), 'belongs to program');
    }

    public function test_a_failed_write_leaves_neither_the_transaction_nor_any_posting(): void
    {
        $this->failInsertsInto('mlm_ledger_postings');

        try {
            $this->postLedger($this->program, [[$this->clearing, '-100'], [$this->alice, '100']]);
            $this->fail('The transaction was posted although its postings could not be written.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The disk is full.', $exception->getMessage());
        }

        $this->assertSame([0, 0], [DB::table('mlm_ledger_transactions')->count(), DB::table('mlm_ledger_postings')->count()]);
    }

    public function test_an_identical_replay_returns_the_posted_transaction(): void
    {
        $first = $this->postLedger($this->program, [[$this->clearing, '-100'], [$this->alice, '60'], [$this->bob, '40']]);
        $before = $this->ledgerRows();

        $again = $this->postLedger($this->program, [[$this->clearing, '-100'], [$this->alice, '60'], [$this->bob, '40']]);
        $reordered = $this->postLedger($this->program, [[$this->bob, '40'], [$this->clearing, '-100.000'], [$this->alice, '60']]);
        $sameInstant = $this->ledger()->post($this->postCommand($this->program, [[$this->clearing, '-100'], [$this->alice, '60'], [$this->bob, '40']], at: '2026-06-01 19:00:00 Asia/Jakarta'));

        $this->assertSame([$first->id, $first->id, $first->id], [$again->id, $reordered->id, $sameInstant->id]);
        $this->assertSame($before, $this->ledgerRows());
    }

    /**
     * @return array<string, array{Closure(self): PostLedgerTransaction, string}>
     */
    public static function conflictingReplays(): array
    {
        return [
            'another amount' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, [[$test->clearing, '-100'], [$test->alice, '70'], [$test->bob, '30']]),
                'differs in postings',
            ],
            'another account' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, [[$test->clearing, '-100'], [$test->alice, '100']]),
                'differs in postings',
            ],
            'another source id' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, $test->lines(), sourceId: 'ADJ-2'),
                'differs in source_id',
            ],
            'another source type' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, $test->lines(), sourceType: 'import'),
                'differs in source_type',
            ],
            'another moment' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, $test->lines(), at: '2026-06-01 12:00:01'),
                'differs in occurred_at',
            ],
            'another type' => [
                static fn (self $test): PostLedgerTransaction => $test->postCommand($test->program, $test->lines(), type: 'correction'),
                'differs in type',
            ],
        ];
    }

    /**
     * @param  Closure(self): PostLedgerTransaction  $replay
     */
    #[DataProvider('conflictingReplays')]
    public function test_a_replay_that_differs_is_refused(Closure $replay, string $reason): void
    {
        $original = $this->postLedger($this->program, $this->lines());
        $before = $this->ledgerRows();

        try {
            $this->ledger()->post($replay($this));
            $this->fail('A conflicting replay was accepted.');
        } catch (ConflictingLedgerReplay $exception) {
            $this->assertStringContainsString("already posted ledger transaction [{$original->id}], which {$reason}", $exception->getMessage());
        }

        $this->assertSame($before, $this->ledgerRows());
    }

    public function test_a_replay_in_another_currency_is_refused(): void
    {
        $this->postLedger($this->program, $this->lines());
        $clearing = $this->systemAccounts()->openSystemAccount($this->program, 'USD', 'adjustment.clearing');
        $dollars = $this->walletAccount(Member::query()->findOrFail($this->alice->wallet?->member_id), 'USD');

        $this->expectException(ConflictingLedgerReplay::class);
        $this->expectExceptionMessage('differs in currency, postings');

        $this->ledger()->post($this->postCommand($this->program, [[$clearing, '-100'], [$dollars, '100']], currency: 'USD'));
    }

    public function test_keys_are_scoped_to_their_program(): void
    {
        $other = Program::factory()->create();
        $clearing = $this->systemAccounts()->openSystemAccount($other, 'IDR', 'adjustment.clearing');
        $carol = $this->walletAccount(Member::factory()->for($other)->create());

        $here = $this->postLedger($this->program, $this->lines());
        $there = $this->postLedger($other, [[$clearing, '-1'], [$carol, '1']]);

        $this->assertNotSame($here->id, $there->id);
        $this->assertSame($here->idempotency_key, $there->idempotency_key);
    }

    public function test_posting_and_replaying_inside_a_callers_transaction(): void
    {
        [$first, $again] = DB::transaction(fn (): array => [
            $this->postLedger($this->program, $this->lines()),
            $this->postLedger($this->program, $this->lines()),
        ]);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, LedgerTransaction::query()->count());
    }

    /**
     * @return array<string, array{Closure(LedgerTransaction): mixed}>
     */
    public static function modelWrites(): array
    {
        return [
            'creating a transaction' => [static fn (LedgerTransaction $transaction): mixed => (new LedgerTransaction)->forceFill($transaction->only(['program_id', 'currency', 'type', 'source_type', 'source_id', 'occurred_at']) + ['idempotency_key' => 'other'])->save()],
            'changing a transaction' => [static fn (LedgerTransaction $transaction): mixed => $transaction->forceFill(['type' => 'correction'])->save()],
            'deleting a transaction' => [static fn (LedgerTransaction $transaction): mixed => $transaction->delete()],
            'creating a posting' => [static fn (LedgerTransaction $transaction): mixed => (new LedgerPosting)->forceFill(['ledger_transaction_id' => $transaction->id, 'ledger_account_id' => $transaction->postings->first()?->ledger_account_id, 'amount_millionths' => 1])->save()],
            'changing a posting' => [static fn (LedgerTransaction $transaction): mixed => $transaction->postings->first()?->forceFill(['amount_millionths' => 1])->save()],
            'deleting a posting' => [static fn (LedgerTransaction $transaction): mixed => $transaction->postings->first()?->delete()],
        ];
    }

    /**
     * @param  Closure(LedgerTransaction): mixed  $write
     */
    #[DataProvider('modelWrites')]
    public function test_the_ledger_is_read_only_through_eloquent(Closure $write): void
    {
        $transaction = $this->postLedger($this->program, $this->lines());
        $before = $this->ledgerRows();

        try {
            $write($transaction);
            $this->fail('A ledger row was written through its model.');
        } catch (ImmutableFinancialRecord $exception) {
            $this->assertStringContainsString('written only by PandaBear\Mlm\Finance\LedgerRecorder', $exception->getMessage());
        }

        $this->assertSame($before, $this->ledgerRows());
    }

    /**
     * The transaction most tests post: clearing pays Alice and Bob.
     *
     * @return list<array{LedgerAccount, string}>
     */
    public function lines(): array
    {
        return [[$this->clearing, '-100'], [$this->alice, '60'], [$this->bob, '40']];
    }

    private function assertRefused(PostLedgerTransaction $command, string $reason): void
    {
        $before = $this->ledgerRows();

        try {
            $this->ledger()->post($command);
            $this->fail('The transaction was posted.');
        } catch (InvalidLedgerTransaction $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        $this->assertSame($before, $this->ledgerRows());
    }

    /**
     * @param  array<string, string>  $postings
     * @return array<string, string>
     */
    private function sorted(array $postings): array
    {
        ksort($postings, SORT_STRING);

        return $postings;
    }
}
