<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Payout;

use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use PandaBear\Mlm\Exceptions\ConflictingPayoutBatch;
use PandaBear\Mlm\Exceptions\CorruptPayoutBatch;
use PandaBear\Mlm\Exceptions\InvalidPayoutBatch;
use PandaBear\Mlm\Finance\CurrencyCode;
use PandaBear\Mlm\Finance\FinanceInput;
use PandaBear\Mlm\Models\PayoutBatch;
use PandaBear\Mlm\Models\PayoutBatchItem;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\Program;

/**
 * Groups approved payout requests of one program and currency, and moves
 * them through processing together (ADR-030) — the only way batches and
 * their items are written. A batch owns no money and never moves any: each
 * request keeps its own reservation, and is settled or failed on its own
 * through `PayoutManager`.
 *
 * open → sealed → processing → completed; an open batch may be cancelled,
 * which abandons the grouping and leaves its requests as they are.
 * Requests join an open batch only, each once, in the order added;
 * starting a sealed batch starts every request, all or none; a batch
 * completes once every request is settled or failed.
 *
 * Every step locks the batch first, then its requests in id order — the
 * order everything here takes; request steps never lock a batch.
 */
final readonly class PayoutBatchManager
{
    private const BATCHES = 'mlm_payout_batches';

    private const ITEMS = 'mlm_payout_batch_items';

    /**
     * @throws ConflictingPayoutBatch
     */
    public function create(Program $program, CurrencyCode|string $currency, string $idempotencyKey): PayoutBatch
    {
        $currency = CurrencyCode::from($currency)->value();

        if (! FinanceInput::isText($idempotencyKey, FinanceInput::IDEMPOTENCY_KEY_LENGTH)) {
            throw InvalidPayoutBatch::because('new', 'an idempotency key is 1 to 191 printable characters; '.var_export($idempotencyKey, true).' given.');
        }

        $db = $program->getConnection();
        $connection = (string) $db->getName();

        return $db->transaction(static function () use ($db, $connection, $program, $currency, $idempotencyKey): PayoutBatch {
            $program = Program::on($connection)->whereKey($program->getKey())->lockForUpdate()->firstOrFail();
            $existing = PayoutBatch::on($connection)->where('program_id', $program->getKey())->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($existing !== null) {
                if ($existing->currency !== $currency) {
                    throw ConflictingPayoutBatch::forKey($existing, $currency);
                }

                return $existing;
            }

            $batch = new PayoutBatch;
            $id = $batch->newUniqueId();
            $now = $batch->freshTimestamp();

            $db->table(self::BATCHES)->insert([
                'id' => $id,
                'program_id' => $program->getKey(),
                'currency' => $currency,
                'idempotency_key' => $idempotencyKey,
                'status' => PayoutBatchStatus::Open->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return PayoutBatch::on($connection)->findOrFail($id);
        });
    }

    /**
     * Adds an approved request to an open batch, at the next position.
     * Adding it again returns its item; a request is in one batch at most.
     *
     * @throws InvalidPayoutBatch
     */
    public function add(PayoutBatch $batch, PayoutRequest $request): PayoutBatchItem
    {
        return $this->step($batch, static function (Connection $db, PayoutBatch $current) use ($request): PayoutBatchItem {
            $connection = (string) $db->getName();
            $stored = PayoutRequest::on($connection)->whereKey($request->getKey())->lockForUpdate()->first()
                ?? throw InvalidPayoutBatch::because((string) $current->getKey(), "payout request [{$request->getKey()}] does not exist.");
            $item = PayoutBatchItem::on($connection)->where('payout_request_id', $stored->getKey())->first();

            if ($item !== null) {
                if ($item->payout_batch_id !== $current->getKey()) {
                    throw InvalidPayoutBatch::because((string) $current->getKey(), "payout request [{$stored->getKey()}] already belongs to payout batch [{$item->payout_batch_id}]; a request is in one batch at most.");
                }

                return $item;
            }

            $problem = match (true) {
                $current->status !== PayoutBatchStatus::Open => "it is {$current->status->value}; requests join an open batch only.",
                $stored->status !== PayoutRequestStatus::Approved => "payout request [{$stored->getKey()}] is {$stored->status->value}; only an approved request joins a batch.",
                $stored->program_id !== $current->program_id => "payout request [{$stored->getKey()}] belongs to another program.",
                $stored->currency !== $current->currency => "payout request [{$stored->getKey()}] is in {$stored->currency}, the batch in {$current->currency}.",
                default => null,
            };

            if ($problem !== null) {
                throw InvalidPayoutBatch::because((string) $current->getKey(), $problem);
            }

            PayoutLedger::verify($db, $stored);

            $item = new PayoutBatchItem;
            $id = $item->newUniqueId();
            $now = $item->freshTimestamp();

            $db->table(self::ITEMS)->insert([
                'id' => $id,
                'payout_batch_id' => $current->getKey(),
                'payout_request_id' => $stored->getKey(),
                'position' => (int) $db->table(self::ITEMS)->where('payout_batch_id', $current->getKey())->max('position') + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return PayoutBatchItem::on($connection)->findOrFail($id);
        });
    }

    /**
     * Fixes the batch's membership and order. No money moves.
     *
     * @throws InvalidPayoutBatch
     */
    public function seal(PayoutBatch $batch, DateTimeInterface $at): PayoutBatch
    {
        $at = FinanceInput::moment($at);

        return $this->step($batch, function (Connection $db, PayoutBatch $current) use ($at): PayoutBatch {
            if ($current->status !== PayoutBatchStatus::Open) {
                return $this->unlessEarlier($current, PayoutBatchStatus::Sealed, 'sealed');
            }

            if ($this->requests($db, $current)->isEmpty()) {
                throw InvalidPayoutBatch::because((string) $current->getKey(), 'it has no request; an empty batch is not sealed.');
            }

            return $this->move($db, $current, PayoutBatchStatus::Sealed, ['sealed_at' => $at]);
        });
    }

    /**
     * Starts every request of a sealed batch — each still approved, its
     * reservation intact — together, all or none. No money moves.
     *
     * @throws InvalidPayoutBatch
     */
    public function startProcessing(PayoutBatch $batch, DateTimeInterface $at): PayoutBatch
    {
        $at = FinanceInput::moment($at);

        return $this->step($batch, function (Connection $db, PayoutBatch $current) use ($at): PayoutBatch {
            if ($current->status !== PayoutBatchStatus::Sealed) {
                return $this->unlessEarlier($current, PayoutBatchStatus::Processing, 'started');
            }

            $requests = $this->requests($db, $current);
            $waiting = $requests->reject(static fn (PayoutRequest $request): bool => $request->status === PayoutRequestStatus::Approved);

            if ($waiting->isNotEmpty()) {
                throw InvalidPayoutBatch::because((string) $current->getKey(), 'every request must still be approved to start, but ['.implode(', ', $waiting->modelKeys()).'] are not.');
            }

            foreach ($requests as $request) {
                PayoutLedger::verify($db, $request);
            }

            $started = 0;

            foreach (array_chunk($requests->modelKeys(), 500) as $chunk) {
                $started += $db->table('mlm_payout_requests')->whereIn('id', $chunk)->where('status', PayoutRequestStatus::Approved->value)->update([
                    'status' => PayoutRequestStatus::Processing->value,
                    'processing_at' => $at,
                    'updated_at' => $current->freshTimestamp(),
                ]);
            }

            if ($started !== $requests->count()) {
                throw new LogicException("Payout batch [{$current->getKey()}] changed while it was locked.");
            }

            return $this->move($db, $current, PayoutBatchStatus::Processing, ['processing_at' => $at]);
        });
    }

    /**
     * Completes a processing batch once every request is settled or
     * failed. No money moves.
     *
     * @throws InvalidPayoutBatch
     * @throws CorruptPayoutBatch
     */
    public function complete(PayoutBatch $batch, DateTimeInterface $at): PayoutBatch
    {
        $at = FinanceInput::moment($at);

        return $this->step($batch, function (Connection $db, PayoutBatch $current) use ($at): PayoutBatch {
            $requests = $this->requests($db, $current);
            $open = $requests->reject(static fn (PayoutRequest $request): bool => in_array($request->status, [PayoutRequestStatus::Settled, PayoutRequestStatus::Failed], true));

            if ($current->status === PayoutBatchStatus::Completed) {
                if ($open->isNotEmpty()) {
                    throw CorruptPayoutBatch::because((string) $current->getKey(), 'it is completed, yet requests ['.implode(', ', $open->modelKeys()).'] are neither settled nor failed');
                }

                return $current;
            }

            if ($current->status !== PayoutBatchStatus::Processing) {
                throw InvalidPayoutBatch::because((string) $current->getKey(), "it is {$current->status->value}; only a processing batch completes.");
            }

            if ($requests->contains(static fn (PayoutRequest $request): bool => in_array($request->status, [PayoutRequestStatus::Requested, PayoutRequestStatus::Approved, PayoutRequestStatus::Cancelled], true))) {
                throw CorruptPayoutBatch::because((string) $current->getKey(), 'it is processing, yet some of its requests never started');
            }

            if ($open->isNotEmpty()) {
                throw InvalidPayoutBatch::because((string) $current->getKey(), 'requests ['.implode(', ', $open->modelKeys()).'] are still processing; a batch completes once every request is settled or failed.');
            }

            return $this->move($db, $current, PayoutBatchStatus::Completed, ['completed_at' => $at]);
        });
    }

    /**
     * Abandons an open batch. Its requests stay as they are, and stay its.
     *
     * @throws InvalidPayoutBatch
     */
    public function cancel(PayoutBatch $batch, DateTimeInterface $at): PayoutBatch
    {
        $at = FinanceInput::moment($at);

        return $this->step($batch, function (Connection $db, PayoutBatch $current) use ($at): PayoutBatch {
            if ($current->status === PayoutBatchStatus::Cancelled) {
                return $current;
            }

            if ($current->status !== PayoutBatchStatus::Open) {
                throw InvalidPayoutBatch::because((string) $current->getKey(), "it is {$current->status->value}; only an open batch is cancelled.");
            }

            return $this->move($db, $current, PayoutBatchStatus::Cancelled, ['cancelled_at' => $at]);
        });
    }

    /**
     * @template TResult
     *
     * @param  callable(Connection, PayoutBatch): TResult  $step
     * @return TResult
     */
    private function step(PayoutBatch $batch, callable $step): mixed
    {
        $db = $batch->getConnection();

        return $db->transaction(static function () use ($db, $batch, $step): mixed {
            $current = PayoutBatch::on((string) $db->getName())->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            return $step($db, $current);
        });
    }

    /**
     * The batch's requests, locked in id order, once its items are checked:
     * positions 1, 2, 3…, every request of its program and currency.
     *
     * @return Collection<int, PayoutRequest>
     */
    private function requests(Connection $db, PayoutBatch $batch): Collection
    {
        $positions = $db->table(self::ITEMS)->where('payout_batch_id', $batch->getKey())->orderBy('position')->pluck('position')->map(static fn (mixed $position): int => (int) $position)->all();

        if ($positions !== ($positions === [] ? [] : range(1, count($positions)))) {
            throw CorruptPayoutBatch::because((string) $batch->getKey(), 'its positions are not 1, 2, 3…');
        }

        $requests = PayoutRequest::on((string) $db->getName())
            ->whereIn('id', $db->table(self::ITEMS)->select('payout_request_id')->where('payout_batch_id', $batch->getKey()))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($requests as $request) {
            if ($request->program_id !== $batch->program_id || $request->currency !== $batch->currency) {
                throw CorruptPayoutBatch::because((string) $batch->getKey(), "payout request [{$request->getKey()}] is of another program or currency");
            }
        }

        return $requests;
    }

    /**
     * A step already taken returns the batch; one the batch has not reached
     * yet — or never will — is refused.
     */
    private function unlessEarlier(PayoutBatch $current, PayoutBatchStatus $step, string $verb): PayoutBatch
    {
        $order = [PayoutBatchStatus::Open, PayoutBatchStatus::Sealed, PayoutBatchStatus::Processing, PayoutBatchStatus::Completed];
        $reached = array_search($current->status, $order, true);

        if ($reached === false || $reached < array_search($step, $order, true)) {
            throw InvalidPayoutBatch::because((string) $current->getKey(), "it is {$current->status->value}, so it cannot be {$verb}.");
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function move(Connection $db, PayoutBatch $current, PayoutBatchStatus $to, array $columns): PayoutBatch
    {
        $moved = $db->table(self::BATCHES)->where('id', $current->getKey())->where('status', $current->status->value)->update([
            'status' => $to->value,
            ...$columns,
            'updated_at' => $current->freshTimestamp(),
        ]);

        if ($moved !== 1) {
            throw new LogicException("Payout batch [{$current->getKey()}] changed while it was locked.");
        }

        return PayoutBatch::on((string) $db->getName())->findOrFail($current->getKey());
    }
}
