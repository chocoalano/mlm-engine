<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Volume;

use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use PandaBear\Mlm\Exceptions\ConflictingVolumeReplay;
use PandaBear\Mlm\Exceptions\InvalidVolumeReversal;
use PandaBear\Mlm\Models\VolumeEntry;

/**
 * The only supported way to write volume history.
 *
 * Each operation writes exactly one immutable row. Replays are recognised by
 * the caller's idempotency key within the program: an identical replay
 * returns the entry already recorded, a conflicting one is refused. The
 * unique keys on (program_id, idempotency_key) and on reversal_of_id are the
 * concurrency backstop — a write that loses a race to the same key, or to a
 * reversal of the same entry, is resolved from the row that won.
 */
final class VolumeRecorder
{
    private const TABLE = 'mlm_volume_entries';

    public function record(RecordVolume $command): VolumeEntry
    {
        // The program comes from the stored member, never from the instance
        // passed in: an entry cannot claim a program its member is not in.
        $member = $command->member->newQuery()->findOrFail($command->member->getKey());
        $connection = $member->getConnection();

        $row = [
            'program_id' => $member->program_id,
            'member_id' => $member->getKey(),
            'type' => $command->type,
            'quantity_millionths' => $command->quantity->toMillionths(),
            'source_type' => $command->sourceType,
            'source_id' => $command->sourceId,
            'idempotency_key' => $command->idempotencyKey,
            'effective_at' => $command->effectiveAt,
            'reversal_of_id' => null,
        ];

        $existing = $this->findByKey($connection, $row);

        if ($existing !== null) {
            return $this->replay($existing, $row);
        }

        try {
            return $this->insert($connection, $row);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->replay($this->findByKey($connection, $row) ?? throw $exception, $row);
        }
    }

    /**
     * Records a second entry that negates the first: same program, member
     * and type, the opposite quantity, the reversal's own source and moment.
     * The original is never changed.
     */
    public function reverse(ReverseVolume $command): VolumeEntry
    {
        $original = $command->entry->newQuery()->findOrFail($command->entry->getKey());
        $connection = $original->getConnection();

        if ($original->reversal_of_id !== null) {
            throw InvalidVolumeReversal::ofAReversal($original);
        }

        $row = [
            'program_id' => $original->program_id,
            'member_id' => $original->member_id,
            'type' => $original->type,
            'quantity_millionths' => $original->quantity->negate()->toMillionths(),
            'source_type' => $command->sourceType,
            'source_id' => $command->sourceId,
            'idempotency_key' => $command->idempotencyKey,
            'effective_at' => $command->effectiveAt,
            'reversal_of_id' => $original->getKey(),
        ];

        $existing = $this->findByKey($connection, $row);

        if ($existing !== null) {
            return $this->replay($existing, $row);
        }

        $this->assertNotReversed($connection, $original);

        try {
            return $this->insert($connection, $row);
        } catch (UniqueConstraintViolationException $exception) {
            // Lost a race: the same key was written, or the entry was
            // reversed under another key.
            $existing = $this->findByKey($connection, $row);

            if ($existing !== null) {
                return $this->replay($existing, $row);
            }

            $this->assertNotReversed($connection, $original);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insert(Connection $connection, array $row): VolumeEntry
    {
        $entry = new VolumeEntry;
        $id = $entry->newUniqueId();
        $now = $entry->freshTimestamp();

        // Its own transaction — a savepoint inside the caller's, if there is
        // one — so a duplicate key rolls back this insert alone, and a
        // surrounding PostgreSQL transaction survives for the re-read.
        $connection->transaction(static fn (): bool => $connection->table(self::TABLE)->insert([
            'id' => $id,
            ...$row,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        return VolumeEntry::on($connection->getName())->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function findByKey(Connection $connection, array $row): ?VolumeEntry
    {
        return VolumeEntry::on($connection->getName())
            ->where('program_id', $row['program_id'])
            ->where('idempotency_key', $row['idempotency_key'])
            ->first();
    }

    /**
     * The entry already recorded under the key, if the request matches it in
     * every material field; refused otherwise.
     *
     * @param  array<string, mixed>  $row
     */
    private function replay(VolumeEntry $existing, array $row): VolumeEntry
    {
        $stored = [
            'program' => $existing->program_id,
            'member' => $existing->member_id,
            'type' => $existing->type,
            'quantity' => (string) $existing->quantity_millionths,
            'source_type' => $existing->source_type,
            'source_id' => $existing->source_id,
            'effective_at' => $existing->effective_at->format('Y-m-d H:i:s'),
            'reversal_of' => (string) $existing->reversal_of_id,
        ];

        $requested = [
            'program' => $row['program_id'],
            'member' => $row['member_id'],
            'type' => $row['type'],
            'quantity' => (string) $row['quantity_millionths'],
            'source_type' => $row['source_type'],
            'source_id' => $row['source_id'],
            'effective_at' => $row['effective_at']->format('Y-m-d H:i:s'),
            'reversal_of' => (string) $row['reversal_of_id'],
        ];

        $conflicts = array_keys(array_diff_assoc($requested, $stored));

        if ($conflicts !== []) {
            throw ConflictingVolumeReplay::forKey($existing, $conflicts);
        }

        return $existing;
    }

    private function assertNotReversed(Connection $connection, VolumeEntry $original): void
    {
        $reversalId = $connection->table(self::TABLE)->where('reversal_of_id', $original->getKey())->value('id');

        if (is_string($reversalId)) {
            throw InvalidVolumeReversal::alreadyReversed($original, $reversalId);
        }
    }
}
