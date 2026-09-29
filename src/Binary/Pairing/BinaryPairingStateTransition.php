<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Pairing;

use Illuminate\Database\Connection;
use PandaBear\Mlm\Commission\CommissionStateTransition;
use PandaBear\Mlm\Exceptions\InvalidBinaryPairingState;
use PandaBear\Mlm\Models\BinaryCarryLot;
use PandaBear\Mlm\Models\BinaryPairingAllocation;
use PandaBear\Mlm\Models\BinaryPairingCorrection;
use PandaBear\Mlm\Models\BinaryPairingCursor;
use PandaBear\Mlm\Models\BinaryPairingRestoration;
use PandaBear\Mlm\Models\BinaryPairingResult;
use PandaBear\Mlm\Models\CalculationRun;

/**
 * Commits one binary pairing run's state (ADR-023, ADR-025), inside the
 * run's own transaction, after its commissions: the cursor moves to the
 * run's end; the new lots are stored; stored lots are taken back, given back
 * or drawn down; each undone pair is journalled with what it gave back; and
 * each member's result is written with every lot its pairs consumed. The
 * only writer of binary pairing state.
 *
 * It checks first that what the calculation read is still what is stored —
 * the cursor, every stored lot it changes, and what earlier corrections had
 * released of every allocation it undoes — and refuses rather than
 * overwrite anything newer.
 */
final readonly class BinaryPairingStateTransition implements CommissionStateTransition
{
    private const CURSORS = 'mlm_binary_pairing_cursors';

    private const LOTS = 'mlm_binary_carry_lots';

    private const RESULTS = 'mlm_binary_pairing_results';

    private const ALLOCATIONS = 'mlm_binary_pairing_allocations';

    private const CORRECTIONS = 'mlm_binary_pairing_corrections';

    private const RESTORATIONS = 'mlm_binary_pairing_restorations';

    private const COMMISSIONS = 'mlm_commissions';

    private const CHUNK = 500;

    public function __construct(private BinaryPairingPlan $plan) {}

    public function apply(Connection $connection, CalculationRun $run): void
    {
        $now = $run->freshTimestamp();

        $this->moveCursor($connection, $run, $now);
        $this->lockStoredLots($connection);
        $this->checkReleases($connection);
        $lots = $this->storeNewLots($connection, $now);
        $this->changeStoredLots($connection, $now);
        $this->storeCorrections($connection, $run, $now);
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
     * Every stored lot the run read and may change, locked and compared with
     * what the calculation read: what it held, and that no reversal had
     * taken it back.
     */
    private function lockStoredLots(Connection $db): void
    {
        $expected = $this->plan->storedLots();

        foreach (array_chunk(array_keys($expected), self::CHUNK) as $chunk) {
            $stored = $db->table(self::LOTS)
                ->whereIn('id', $chunk)
                ->where('plan_component_id', $this->plan->component)
                ->lockForUpdate()
                ->get(['id', 'remaining_millionths', 'reversed_by_volume_entry_id'])
                ->keyBy('id');

            foreach ($chunk as $id) {
                $lot = $stored->get($id);

                if ($lot === null || (string) $lot->remaining_millionths !== $expected[$id]['expected'] || $lot->reversed_by_volume_entry_id !== null) {
                    throw InvalidBinaryPairingState::changed($this->plan->component, "carry lot [{$id}] is no longer as it was read");
                }
            }
        }
    }

    /**
     * What earlier corrections had released of every allocation this run
     * undoes, compared with what the calculation read.
     */
    private function checkReleases(Connection $db): void
    {
        $expected = $this->plan->releases();
        $stored = [];

        foreach ([self::CORRECTIONS => 'invalidated_allocation_id', self::RESTORATIONS => 'restored_allocation_id'] as $table => $column) {
            foreach (array_chunk(array_keys($expected), self::CHUNK) as $chunk) {
                foreach ($db->table($table)->whereIn($column, $chunk)->get([$column, 'quantity_millionths']) as $row) {
                    $stored[$table][$row->{$column}] = PairingArithmetic::add($stored[$table][$row->{$column}] ?? '0', (string) $row->quantity_millionths);
                }
            }
        }

        foreach ($expected as $allocation => [$invalidated, $restored]) {
            if (($stored[self::CORRECTIONS][$allocation] ?? '0') !== $invalidated || ($stored[self::RESTORATIONS][$allocation] ?? '0') !== $restored) {
                throw InvalidBinaryPairingState::changed($this->plan->component, "allocation [{$allocation}] was corrected since it was read");
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

    /**
     * Each stored lot as the run leaves it: what it holds, and the reversal
     * that took it back, if one did.
     */
    private function changeStoredLots(Connection $db, mixed $now): void
    {
        foreach ($this->plan->storedLots() as $id => $lot) {
            if ($lot['remaining'] === $lot['expected'] && $lot['reversed_by'] === null) {
                continue;
            }

            $db->table(self::LOTS)->where('id', $id)->update([
                'remaining_millionths' => $lot['remaining'],
                'reversed_by_volume_entry_id' => $lot['reversed_by'],
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Every undone pair, and what it gave back.
     */
    private function storeCorrections(Connection $db, CalculationRun $run, mixed $now): void
    {
        $corrections = [];
        $restorations = [];

        foreach ($this->plan->corrections() as $correction) {
            $id = (new BinaryPairingCorrection)->newUniqueId();
            $corrections[] = [
                'id' => $id,
                'program_id' => $this->plan->program,
                'plan_component_id' => $this->plan->component,
                'calculation_run_id' => $run->getKey(),
                'reversal_volume_entry_id' => $correction['reversal'],
                'original_volume_entry_id' => $correction['original'],
                'binary_pairing_result_id' => $correction['result'],
                'invalidated_allocation_id' => $correction['allocation'],
                'member_id' => $correction['member'],
                'invalidated_side' => $correction['side']->value,
                'quantity_millionths' => $correction['quantity'],
                'commission_id' => $correction['commission'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach ($correction['restorations'] as $restoration) {
                $restorations[] = [
                    'id' => (new BinaryPairingRestoration)->newUniqueId(),
                    'binary_pairing_correction_id' => $id,
                    'restored_allocation_id' => $restoration['allocation'],
                    'binary_carry_lot_id' => $restoration['lot'],
                    'side' => $restoration['side']->value,
                    'quantity_millionths' => $restoration['quantity'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($corrections, self::CHUNK) as $chunk) {
            $db->table(self::CORRECTIONS)->insert($chunk);
        }

        foreach (array_chunk($restorations, self::CHUNK) as $chunk) {
            $db->table(self::RESTORATIONS)->insert($chunk);
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
