<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\ConflictingLedgerReplay;
use PandaBear\Mlm\Exceptions\InvalidLedgerReversal;
use PandaBear\Mlm\Exceptions\InvalidLedgerTransaction;
use PandaBear\Mlm\Finance\ReverseLedgerTransaction;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;

/**
 * A correction is a new transaction with every posting negated: the original
 * is never touched, is reversed once, and a reversal is not reversed.
 */
final class LedgerReversalTest extends DatabaseTestCase
{
    use BuildsLedgers;

    private Program $program;

    private LedgerAccount $clearing;

    private LedgerAccount $alice;

    private LedgerAccount $bob;

    private LedgerTransaction $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->program = Program::factory()->create();
        $this->clearing = $this->systemAccounts()->openSystemAccount($this->program, 'IDR', 'adjustment.clearing');
        $this->alice = $this->walletAccount(Member::factory()->for($this->program)->create());
        $this->bob = $this->walletAccount(Member::factory()->for($this->program)->create());
        $this->original = $this->postLedger($this->program, [[$this->clearing, '-100.5'], [$this->alice, '60.25'], [$this->bob, '40.25']]);
    }

    public function test_a_reversal_is_a_new_transaction_with_every_posting_negated(): void
    {
        $before = $this->originalRows();

        $reversal = $this->reverseLedger($this->original);

        $this->assertNotSame($this->original->id, $reversal->id);
        $this->assertSame(
            [$this->program->id, 'IDR', 'adjustment', 'manual', 'REV-1', 'reversal:REV-1', '2026-06-15 12:00:00', $this->original->id],
            [$reversal->program_id, $reversal->currency, $reversal->type, $reversal->source_type, $reversal->source_id, $reversal->idempotency_key, $reversal->occurred_at->format('Y-m-d H:i:s'), $reversal->reversal_of_id],
        );
        $inverse = [$this->clearing->id => '100.5', $this->alice->id => '-60.25', $this->bob->id => '-40.25'];
        ksort($inverse, SORT_STRING);
        $this->assertSame($inverse, $this->postingsOf($reversal));

        // Every account is back where it was, and the original is untouched.
        foreach ([$this->clearing, $this->alice, $this->bob] as $account) {
            $this->assertSame('0', $this->balances()->forAccount($account)->value());
        }

        $this->assertSame($before, $this->originalRows());
        $this->assertTrue($reversal->original?->is($this->original));
        $this->assertTrue($this->original->reversal?->is($reversal));
        $this->assertTrue($reversal->isReversal());
        $this->assertFalse($this->original->isReversal());
    }

    public function test_an_identical_reversal_replay_returns_the_reversal(): void
    {
        $reversal = $this->reverseLedger($this->original);
        $before = $this->ledgerRows();

        $this->assertSame($reversal->id, $this->reverseLedger($this->original)->id);
        $this->assertSame($before, $this->ledgerRows());
    }

    public function test_a_transaction_is_reversed_once(): void
    {
        $reversal = $this->reverseLedger($this->original);
        $before = $this->ledgerRows();

        try {
            $this->reverseLedger($this->original, 'reversal:REV-2', 'REV-2');
            $this->fail('The transaction was reversed twice.');
        } catch (InvalidLedgerReversal $exception) {
            $this->assertStringContainsString("was already reversed by transaction [{$reversal->id}]", $exception->getMessage());
        }

        $this->assertSame($before, $this->ledgerRows());
    }

    public function test_a_reversal_is_not_reversed(): void
    {
        $reversal = $this->reverseLedger($this->original);
        $before = $this->ledgerRows();

        try {
            $this->reverseLedger($reversal, 'reversal:REV-2', 'REV-2');
            $this->fail('A reversal was reversed.');
        } catch (InvalidLedgerReversal $exception) {
            $this->assertStringContainsString('is itself a reversal', $exception->getMessage());
        }

        $this->assertSame($before, $this->ledgerRows());
    }

    public function test_a_reversal_replay_that_differs_is_refused(): void
    {
        $this->reverseLedger($this->original);

        $this->expectException(ConflictingLedgerReplay::class);
        $this->expectExceptionMessage('differs in source_id');

        $this->reverseLedger($this->original, 'reversal:REV-1', 'REV-9');
    }

    public function test_a_reversal_under_another_transactions_key_is_refused(): void
    {
        $other = $this->postLedger($this->program, [[$this->clearing, '-1'], [$this->alice, '1']], 'adjustment:ADJ-2', 'ADJ-2');

        try {
            $this->reverseLedger($this->original, 'adjustment:ADJ-2', 'ADJ-2');
            $this->fail('A reversal was recorded under a key already used.');
        } catch (ConflictingLedgerReplay $exception) {
            $this->assertStringContainsString("already posted ledger transaction [{$other->id}], which differs in occurred_at, reversal_of, postings", $exception->getMessage());
        }

        $this->assertNull($this->original->reversal()->first());
    }

    public function test_the_stored_transaction_decides_not_the_instance(): void
    {
        // Claiming in memory that the original is a reversal, of another
        // type and currency, changes nothing...
        $this->original->forceFill(['reversal_of_id' => $this->original->id, 'type' => 'bonus', 'currency' => 'USD']);
        $reversal = $this->reverseLedger($this->original);

        $this->assertSame(['adjustment', 'IDR'], [$reversal->type, $reversal->currency]);

        // ...and claiming a reversal is none does not make it reversible.
        $reversal->forceFill(['reversal_of_id' => null]);

        $this->expectException(InvalidLedgerReversal::class);
        $this->expectExceptionMessage('is itself a reversal');

        $this->reverseLedger($reversal, 'reversal:REV-2', 'REV-2');
    }

    public function test_a_reversal_takes_no_amounts_from_the_caller(): void
    {
        $this->assertSame(
            ['transaction', 'sourceType', 'sourceId', 'idempotencyKey', 'occurredAt'],
            array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), (new \ReflectionMethod(ReverseLedgerTransaction::class, '__construct'))->getParameters()),
        );
    }

    public function test_a_reversal_command_is_validated_like_a_posting(): void
    {
        $this->expectException(InvalidLedgerTransaction::class);
        $this->expectExceptionMessage('ledger source type must be 1–64');

        $this->reverseCommand($this->original, sourceType: 'Refund');
    }

    /**
     * @return array<string, mixed>
     */
    private function originalRows(): array
    {
        return [
            DB::table('mlm_ledger_transactions')->where('id', $this->original->id)->get()->map(static fn (object $row): array => (array) $row)->all(),
            DB::table('mlm_ledger_postings')->where('ledger_transaction_id', $this->original->id)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        ];
    }
}
