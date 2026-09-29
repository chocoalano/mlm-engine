<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Finance;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use PandaBear\Mlm\Exceptions\ConflictingLedgerReplay;
use PandaBear\Mlm\Exceptions\InvalidLedgerReversal;
use PandaBear\Mlm\Exceptions\InvalidLedgerTransaction;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerPosting;
use PandaBear\Mlm\Models\LedgerTransaction;

/**
 * The only supported way to write the ledger.
 *
 * A transaction is written with all its postings in one database
 * transaction — all of it, or nothing — and never changed afterwards. Every
 * account is read from the database and must belong to the transaction's
 * program and currency; a request built from instances changed in memory
 * cannot move value elsewhere.
 *
 * Replays are recognised by the caller's idempotency key within the
 * program: an identical replay — postings in any order — returns the
 * transaction already posted, a conflicting one is refused. The unique keys
 * on (program_id, idempotency_key) and on reversal_of_id are the
 * concurrency backstop: a write that loses a race to the same key, or to a
 * reversal of the same transaction, is resolved from the row that won.
 */
final class LedgerRecorder
{
    private const TRANSACTIONS = 'mlm_ledger_transactions';

    private const POSTINGS = 'mlm_ledger_postings';

    public function post(PostLedgerTransaction $command): LedgerTransaction
    {
        $program = $command->program->newQuery()->findOrFail($command->program->getKey());
        $db = $program->getConnection();
        $currency = $command->currency->value();
        $accounts = $this->accounts($db, array_map(static fn (LedgerPostingInput $posting): string => (string) $posting->account->getKey(), $command->postings));
        $postings = [];

        foreach ($command->postings as $posting) {
            $account = $accounts[(string) $posting->account->getKey()];

            if ($account->program_id !== $program->getKey()) {
                throw InvalidLedgerTransaction::otherProgram($account->getKey(), $account->program_id, $program->getKey());
            }

            if ($account->currency !== $currency) {
                throw InvalidLedgerTransaction::otherCurrency($account->getKey(), $account->currency, $currency);
            }

            $postings[$account->getKey()] = $posting->amount->toMillionths();
        }

        return $this->write($db, [
            'program_id' => $program->getKey(),
            'currency' => $currency,
            'type' => $command->type,
            'source_type' => $command->sourceType,
            'source_id' => $command->sourceId,
            'idempotency_key' => $command->idempotencyKey,
            'occurred_at' => $command->occurredAt,
            'reversal_of_id' => null,
        ], $postings);
    }

    /**
     * Posts a second transaction that negates the first: same program,
     * currency, type and accounts, every amount negated, the reversal's own
     * source, key and moment. The original is never changed. A reversal
     * cannot itself be reversed, and a transaction is reversed once.
     */
    public function reverse(ReverseLedgerTransaction $command): LedgerTransaction
    {
        $original = $command->transaction->newQuery()->findOrFail($command->transaction->getKey());
        $db = $original->getConnection();

        if ($original->reversal_of_id !== null) {
            throw InvalidLedgerReversal::ofAReversal($original);
        }

        $postings = array_map(
            static fn (string $amount): string => FinancialAmount::fromMillionths($amount)->negate()->toMillionths(),
            $this->postingsOf($db, $original->getKey()),
        );

        return $this->write($db, [
            'program_id' => $original->program_id,
            'currency' => $original->currency,
            'type' => $original->type,
            'source_type' => $command->sourceType,
            'source_id' => $command->sourceId,
            'idempotency_key' => $command->idempotencyKey,
            'occurred_at' => $command->occurredAt,
            'reversal_of_id' => $original->getKey(),
        ], $postings, $original);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, string>  $postings  millionths by account id
     */
    private function write(Connection $db, array $header, array $postings, ?LedgerTransaction $original = null): LedgerTransaction
    {
        ksort($postings, SORT_STRING);

        $existing = $this->findByKey($db, $header);

        if ($existing !== null) {
            return $this->replay($db, $existing, $header, $postings);
        }

        if ($original !== null) {
            $this->assertNotReversed($db, $original);
        }

        try {
            return $this->insert($db, $header, $postings);
        } catch (UniqueConstraintViolationException $exception) {
            // Lost a race: the same key was written, or the original was
            // reversed under another key.
            $existing = $this->findByKey($db, $header, lock: true);

            if ($existing !== null) {
                return $this->replay($db, $existing, $header, $postings);
            }

            if ($original !== null) {
                $this->assertNotReversed($db, $original, lock: true);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, string>  $postings
     */
    private function insert(Connection $db, array $header, array $postings): LedgerTransaction
    {
        $id = (new LedgerTransaction)->newUniqueId();

        // One write moment for the header and every posting.
        $now = (new LedgerTransaction)->freshTimestamp();

        $rows = [];

        foreach ($postings as $account => $amount) {
            $rows[] = [
                'id' => (new LedgerPosting)->newUniqueId(),
                'ledger_transaction_id' => $id,
                'ledger_account_id' => $account,
                'amount_millionths' => $amount,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // One transaction — a savepoint inside the caller's, if there is one
        // — so the header never exists without its postings, and a lost race
        // rolls back this write alone, leaving a surrounding PostgreSQL
        // transaction usable for the re-read.
        $db->transaction(static function () use ($db, $id, $header, $rows, $now): void {
            $db->table(self::TRANSACTIONS)->insert(['id' => $id, ...$header, 'created_at' => $now, 'updated_at' => $now]);
            $db->table(self::POSTINGS)->insert($rows);
        });

        return LedgerTransaction::on($db->getName())->findOrFail($id);
    }

    /**
     * Every account a request names, as stored, by id.
     *
     * @param  list<string>  $ids
     * @return array<string, LedgerAccount>
     */
    private function accounts(Connection $db, array $ids): array
    {
        $read = static fn (array $ids, bool $lock): array => LedgerAccount::on($db->getName())
            ->whereKey($ids)
            ->when($lock, static fn (Builder $query): Builder => $query->sharedLock())
            ->get()
            ->keyBy(static fn (LedgerAccount $account): string => (string) $account->getKey())
            ->all();

        $accounts = $read($ids, false);
        $missing = array_values(array_diff($ids, array_keys($accounts)));

        // An account opened by another session after a caller's MySQL
        // transaction took its snapshot is seen by a locking read only.
        if ($missing !== []) {
            $accounts += $read($missing, true);
        }

        foreach ($ids as $id) {
            if (! isset($accounts[$id])) {
                throw InvalidLedgerTransaction::missingAccount($id);
            }
        }

        return $accounts;
    }

    /**
     * A transaction's postings as stored: millionths by account id, in
     * account order.
     *
     * @return array<string, string>
     */
    private function postingsOf(Connection $db, string $transaction): array
    {
        $read = static fn (bool $lock): array => $db->table(self::POSTINGS)
            ->where('ledger_transaction_id', $transaction)
            ->when($lock, static fn (QueryBuilder $query): QueryBuilder => $query->sharedLock())
            ->orderBy('ledger_account_id')
            ->pluck('amount_millionths', 'ledger_account_id')
            ->map(static fn (mixed $amount): string => (string) $amount)
            ->all();

        // A stored transaction always has postings: none visible means a
        // caller's MySQL transaction is reading a snapshot taken before they
        // were committed, and a locking read sees them.
        $postings = $read(false);

        return $postings !== [] ? $postings : $read(true);
    }

    /**
     * After a lost race, `$lock` makes the read a locking one, which sees the
     * row that won: a plain read inside a caller's transaction on MySQL sees
     * the snapshot taken by that transaction's first read.
     *
     * @param  array<string, mixed>  $header
     */
    private function findByKey(Connection $db, array $header, bool $lock = false): ?LedgerTransaction
    {
        return LedgerTransaction::on($db->getName())
            ->where('program_id', $header['program_id'])
            ->where('idempotency_key', $header['idempotency_key'])
            ->when($lock, static fn (Builder $query): Builder => $query->sharedLock())
            ->first();
    }

    /**
     * The transaction already posted under the key, if the request matches
     * it in every material field — its postings compared by account, in any
     * order; refused otherwise.
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, string>  $postings  in account order
     */
    private function replay(Connection $db, LedgerTransaction $existing, array $header, array $postings): LedgerTransaction
    {
        $stored = [
            'currency' => $existing->currency,
            'type' => $existing->type,
            'source_type' => $existing->source_type,
            'source_id' => $existing->source_id,
            'occurred_at' => $existing->occurred_at->format('Y-m-d H:i:s'),
            'reversal_of' => (string) $existing->reversal_of_id,
            'postings' => json_encode($this->postingsOf($db, $existing->getKey()), JSON_THROW_ON_ERROR),
        ];

        $requested = [
            'currency' => $header['currency'],
            'type' => $header['type'],
            'source_type' => $header['source_type'],
            'source_id' => $header['source_id'],
            'occurred_at' => $header['occurred_at']->format('Y-m-d H:i:s'),
            'reversal_of' => (string) $header['reversal_of_id'],
            'postings' => json_encode($postings, JSON_THROW_ON_ERROR),
        ];

        $conflicts = array_keys(array_diff_assoc($requested, $stored));

        if ($conflicts !== []) {
            throw ConflictingLedgerReplay::forKey($existing, $conflicts);
        }

        return $existing;
    }

    private function assertNotReversed(Connection $db, LedgerTransaction $original, bool $lock = false): void
    {
        $reversal = $db->table(self::TRANSACTIONS)
            ->where('reversal_of_id', $original->getKey())
            ->when($lock, static fn (QueryBuilder $query): QueryBuilder => $query->sharedLock())
            ->value('id');

        if (is_string($reversal)) {
            throw InvalidLedgerReversal::alreadyReversed($original, $reversal);
        }
    }
}
