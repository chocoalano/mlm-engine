<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Pairing;

use Illuminate\Database\Connection;
use PandaBear\Mlm\Commission\CommissionStateTransition;
use PandaBear\Mlm\Exceptions\InvalidBinaryPairingState;
use PandaBear\Mlm\Models\BinaryCarryLot;
use PandaBear\Mlm\Models\BinaryPairingAllocation;
use PandaBear\Mlm\Models\BinaryPairingCursor;
use PandaBear\Mlm\Models\BinaryPairingResult;
use PandaBear\Mlm\Models\CalculationRun;

/**
 * Commits one binary pairing run's state (ADR-023), inside the run's own
 * transaction, after its commissions: the cursor moves to the run's end;
 * the new lots are stored, the reversed ones taken back, the consumed ones
 * drawn down; and each member's result is written with every lot its pairs
 * consumed. The only writer of binary pairing state.
 *
 * It checks first that what the calculation read is still what is stored —
 * the cursor, and every stored lot it changes — and refuses rather than
 * overwrite anything newer.
 */
final readonly class BinaryPairingStateTransition implements CommissionStateTransition
{
    private const CURSORS = 'mlm_binary_pairing_cursors';

    private const LOTS = 'mlm_binary_carry_lots';

    private const RESULTS = 'mlm_binary_pairing_results';

    private const ALLOCATIONS = 'mlm_binary_pairing_allocations';

    private const COMMISSIONS = 'mlm_commissions';

    private const CHUNK = 500;

    public function __construct(private BinaryPairingPlan $plan) {}

    public function apply(Connection $connection, CalculationRun $run): void
    {
        $now = $run->freshTimestamp();

        $this->moveCursor($connection, $run, $now);
        $this->lockStoredLots($connection);
        $lots = $this->storeNewLots($connection, $now);
        $this->drawDownStoredLots($connection, $now);
        $this->storeResults($connection, $run, $lots, $now);
    }

    private function moveCursor(Connection $db, CalculationRun $run, mixed $now): void
    {
        $cursor = $this->plan->cursor;

        if ($cursor === null) {
            // A second first run of the component loses on the unique key.
            $db->table(self::CURSORS)->insert([
                'id' => (new BinaryPairingCursor)->newUniqueId(),
                'program_id' => $this->plan->program,
                'plan_component_id' => $this->plan->component,
                'started_at' => $this->plan->startedAt,
                'through_at' => $this->plan->until,
                'last_calculation_run_id' => $run->getKey(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $moved = $db->table(self::CURSORS)
            ->where('id', $cursor->id)
            ->where('through_at', $cursor->through_at)
            ->where('last_calculation_run_id', $cursor->last_calculation_run_id)
            ->update(['through_at' => $this->plan->until, 'last_calculation_run_id' => $run->getKey(), 'updated_at' => $now]);

        if ($moved !== 1) {
            throw InvalidBinaryPairingState::changed($this->plan->component, "its cursor is no longer at {$cursor->through_at}");
        }
    }

    /**
     * Every stored lot the run changes, locked and compared with what the
     * calculation read.
     */
    private function lockStoredLots(Connection $db): void
    {
        $expected = [];

        foreach ($this->plan->reversedLots() as $id => $lot) {
            $expected[$id] = [$lot['quantity'], null];
        }

        foreach ($this->plan->consumedLots() as $id => $lot) {
            $expected[$id] = [$lot['expected'], null];
        }

        foreach (array_chunk(array_keys($expected), self::CHUNK) as $chunk) {
            $stored = $db->table(self::LOTS)
                ->whereIn('id', $chunk)
                ->where('plan_component_id', $this->plan->component)
                ->lockForUpdate()
                ->get(['id', 'remaining_millionths', 'reversed_by_volume_entry_id'])
                ->keyBy('id');

            foreach ($chunk as $id) {
                $lot = $stored->get($id);

                if ($lot === null || (string) $lot->remaining_millionths !== $expected[$id][0] || $lot->reversed_by_volume_entry_id !== $expected[$id][1]) {
                    throw InvalidBinaryPairingState::changed($this->plan->component, "carry lot [{$id}] is no longer as it was read");
                }
            }
        }
    }

    /**
     * @return array<string, string> the new lots' ids, by member and entry
     */
    private function storeNewLots(Connection $db, mixed $now): array
    {
        $ids = [];
        $rows = [];

        foreach ($this->plan->newLots() as $key => $lot) {
            $ids[$key] = (new BinaryCarryLot)->newUniqueId();
            $rows[] = [
                'id' => $ids[$key],
                'program_id' => $this->plan->program,
                'plan_component_id' => $this->plan->component,
                'member_id' => $lot['member'],
                'side' => $lot['side']->value,
                'source_volume_entry_id' => $lot['entry'],
                'source_effective_at' => $lot['effective_at'],
                'quantity_millionths' => $lot['quantity'],
                'remaining_millionths' => $lot['remaining'],
                'reversed_by_volume_entry_id' => $lot['reversed_by'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Written a chunk at a time: a run may take in many lots.
            if (count($rows) === self::CHUNK) {
                $db->table(self::LOTS)->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $db->table(self::LOTS)->insert($rows);
        }

        return $ids;
    }

    private function drawDownStoredLots(Connection $db, mixed $now): void
    {
        $byReversal = [];

        foreach ($this->plan->reversedLots() as $id => $lot) {
            $byReversal[$lot['reversal']][] = $id;
        }

        foreach ($byReversal as $reversal => $ids) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $db->table(self::LOTS)->whereIn('id', $chunk)->update(['remaining_millionths' => 0, 'reversed_by_volume_entry_id' => $reversal, 'updated_at' => $now]);
            }
        }

        $emptied = [];

        foreach ($this->plan->consumedLots() as $id => $lot) {
            if ($lot['remaining'] === '0') {
                $emptied[] = $id;

                continue;
            }

            $db->table(self::LOTS)->where('id', $id)->update(['remaining_millionths' => $lot['remaining'], 'updated_at' => $now]);
        }

        foreach (array_chunk($emptied, self::CHUNK) as $chunk) {
            $db->table(self::LOTS)->whereIn('id', $chunk)->update(['remaining_millionths' => 0, 'updated_at' => $now]);
        }
    }

    /**
     * @param  array<string, string>  $newLots  ids by member and entry
     */
    private function storeResults(Connection $db, CalculationRun $run, array $newLots, mixed $now): void
    {
        // The run's commissions, by the key the candidates carried: never by
        // the order they were inserted in.
        $commissions = $db->table(self::COMMISSIONS)->where('calculation_run_id', $run->getKey())->pluck('id', 'candidate_key')->all();
        $results = [];
        $allocations = [];

        foreach ($this->plan->results() as $result) {
            $member = (string) $result['member_id'];
            $candidate = $result['candidate'];
            $id = (new BinaryPairingResult)->newUniqueId();
            unset($result['candidate']);

            $results[] = [
                ...$result,
                'id' => $id,
                'calculation_run_id' => $run->getKey(),
                'program_id' => $this->plan->program,
                'plan_component_id' => $this->plan->component,
                'commission_id' => $candidate === null
                    ? null
                    : ($commissions[$candidate] ?? throw InvalidBinaryPairingState::changed($this->plan->component, "the run stored no commission \"{$candidate}\"")),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach ($this->plan->allocations($member) as $allocation) {
                $allocations[] = [
                    'id' => (new BinaryPairingAllocation)->newUniqueId(),
                    'binary_pairing_result_id' => $id,
                    'binary_carry_lot_id' => $allocation['stored'] ? $allocation['lot'] : $newLots[$allocation['lot']],
                    'side' => $allocation['side']->value,
                    'quantity_millionths' => $allocation['quantity'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($results, self::CHUNK) as $chunk) {
            $db->table(self::RESULTS)->insert($chunk);
        }

        foreach (array_chunk($allocations, self::CHUNK) as $chunk) {
            $db->table(self::ALLOCATIONS)->insert($chunk);
        }
    }
}
