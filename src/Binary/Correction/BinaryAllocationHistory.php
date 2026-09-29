<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Correction;

use Illuminate\Database\Connection;
use PandaBear\Mlm\Binary\Pairing\PairingArithmetic;

/**
 * @internal
 *
 * The stored history of binary consumption around a set of carry lots
 * (ADR-024, ADR-025): their allocations, the pairing results and runs those
 * belong to, and what later corrections released of each allocation —
 * invalidated, because its own source was reversed, or restored, because
 * the opposite side's was. Read in a few set-based queries, never one per
 * allocation.
 *
 * An allocation's net consumption is its quantity less both. The one
 * definition the analyzer and the correction planner share. The planner
 * also records what it plans, so later steps of one run see it.
 */
final class BinaryAllocationHistory
{
    private const CHUNK = 500;

    /**
     * @param  array<string, object>  $allocations  by id
     * @param  array<string, object>  $results  by id
     * @param  array<string, object>  $runs  by id
     * @param  array<string, object>  $lots  the allocations' lots, by id
     * @param  array<string, string>  $invalidated  millionths, by allocation
     * @param  array<string, string>  $restored  millionths, by allocation
     */
    private function __construct(
        private array $allocations,
        public readonly array $results,
        public readonly array $runs,
        public readonly array $lots,
        private array $invalidated,
        private array $restored,
    ) {}

    /**
     * The history of these lots — and, with `$opposites`, of every other
     * allocation of the same pairing results, with their lots.
     *
     * @param  list<string>  $lotIds
     */
    public static function load(Connection $db, array $lotIds, bool $opposites = false): self
    {
        $allocations = self::rows($db, 'mlm_binary_pairing_allocations', 'binary_carry_lot_id', $lotIds);
        $results = self::rows($db, 'mlm_binary_pairing_results', 'id', array_column($allocations, 'binary_pairing_result_id'));

        if ($opposites) {
            $allocations += self::rows($db, 'mlm_binary_pairing_allocations', 'binary_pairing_result_id', array_keys($results));
        }

        $runs = self::rows($db, 'mlm_calculation_runs', 'id', array_column($results, 'calculation_run_id'));
        $lots = self::rows($db, 'mlm_binary_carry_lots', 'id', array_column($allocations, 'binary_carry_lot_id'));
        $ids = array_keys($allocations);

        return new self(
            $allocations,
            $results,
            $runs,
            $lots,
            self::sums($db, 'mlm_binary_pairing_corrections', 'invalidated_allocation_id', $ids),
            self::sums($db, 'mlm_binary_pairing_restorations', 'restored_allocation_id', $ids),
        );
    }

    /**
     * The lot's allocations in the order they were consumed: by run — its
     * range, then id — then pairing result and allocation.
     *
     * @return list<object>
     */
    public function ofLot(string $lotId): array
    {
        $allocations = array_values(array_filter($this->allocations, static fn (object $allocation): bool => $allocation->binary_carry_lot_id === $lotId));
        usort($allocations, fn (object $a, object $b): int => $this->order($a) <=> $this->order($b));

        return $allocations;
    }

    /**
     * The allocations on the other side of the allocation's pairing result,
     * newest source first: the reverse of the FIFO order they were consumed
     * in, so undoing takes back the newest part of the pair first.
     *
     * @return list<object>
     */
    public function opposite(object $allocation): array
    {
        $opposite = array_values(array_filter(
            $this->allocations,
            static fn (object $other): bool => $other->binary_pairing_result_id === $allocation->binary_pairing_result_id && $other->side !== $allocation->side,
        ));

        usort($opposite, fn (object $a, object $b): int => $this->fifo($b) <=> $this->fifo($a));

        return $opposite;
    }

    public function allocated(object $allocation): string
    {
        return (string) $allocation->quantity_millionths;
    }

    public function invalidated(object $allocation): string
    {
        return $this->invalidated[$allocation->id] ?? '0';
    }

    public function restored(object $allocation): string
    {
        return $this->restored[$allocation->id] ?? '0';
    }

    /**
     * What of the allocation still counts as paired, in millionths; null
     * when more was released than it ever consumed.
     */
    public function net(object $allocation): ?string
    {
        $released = PairingArithmetic::add($this->invalidated($allocation), $this->restored($allocation));

        return PairingArithmetic::compare($released, $this->allocated($allocation)) > 0
            ? null
            : PairingArithmetic::subtract($this->allocated($allocation), $released);
    }

    public function invalidate(object $allocation, string $quantity): void
    {
        $this->invalidated[$allocation->id] = PairingArithmetic::add($this->invalidated($allocation), $quantity);
    }

    public function restore(object $allocation, string $quantity): void
    {
        $this->restored[$allocation->id] = PairingArithmetic::add($this->restored($allocation), $quantity);
    }

    /**
     * @return array{string, string, string, string, string}
     */
    private function order(object $allocation): array
    {
        $result = $this->results[$allocation->binary_pairing_result_id];
        $run = $this->runs[$result->calculation_run_id];

        return [(string) $run->from_at, (string) $run->until_at, (string) $run->id, (string) $result->id, (string) $allocation->id];
    }

    /**
     * @return array{string, string, string}
     */
    private function fifo(object $allocation): array
    {
        $lot = $this->lots[$allocation->binary_carry_lot_id];

        return [substr((string) $lot->source_effective_at, 0, 19), (string) $lot->source_volume_entry_id, (string) $allocation->id];
    }

    /**
     * @param  list<string>  $values
     * @return array<string, object> by id
     */
    private static function rows(Connection $db, string $table, string $column, array $values): array
    {
        $rows = [];

        foreach (array_chunk(array_values(array_unique($values)), self::CHUNK) as $chunk) {
            foreach ($db->table($table)->whereIn($column, $chunk)->get() as $row) {
                $rows[(string) $row->id] = $row;
            }
        }

        return $rows;
    }

    /**
     * Released millionths by allocation, added exactly here.
     *
     * @param  list<string>  $allocationIds
     * @return array<string, string>
     */
    private static function sums(Connection $db, string $table, string $column, array $allocationIds): array
    {
        $sums = [];

        foreach (array_chunk($allocationIds, self::CHUNK) as $chunk) {
            foreach ($db->table($table)->whereIn($column, $chunk)->get([$column, 'quantity_millionths']) as $row) {
                $sums[$row->{$column}] = PairingArithmetic::add($sums[$row->{$column}] ?? '0', (string) $row->quantity_millionths);
            }
        }

        return $sums;
    }
}
