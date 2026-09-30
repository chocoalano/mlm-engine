<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use PandaBear\Mlm\Calculation\CalculationBatchStatus;
use PandaBear\Mlm\Calculation\CalculationContext;
use PandaBear\Mlm\Calculation\CalculationEngine;
use PandaBear\Mlm\Exceptions\ConflictingCalculationBatch;
use PandaBear\Mlm\Exceptions\ConflictingCalculationReplay;
use PandaBear\Mlm\Exceptions\CorruptCalculationBatch;
use PandaBear\Mlm\Exceptions\InvalidCalculationRun;
use PandaBear\Mlm\Exceptions\InvalidCommissionCandidate;
use PandaBear\Mlm\Exceptions\InvalidHybridCalculation;
use PandaBear\Mlm\Models\CalculationBatch;
use PandaBear\Mlm\Models\CalculationBatchItem;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Planning\PlanVersionStatus;

/**
 * Calculates every commission component of a plan version as one
 * auditable, idempotent batch (ADR-028): hybrid compensation as
 * composition, not a new genealogy or a new strategy.
 *
 * The plan version is the composition: its `commission.strategy`
 * components — two or more — by position, then id; its other components
 * belong to their own engines. Each is calculated by `CalculationEngine`,
 * unchanged — its strategy, validation, snapshot or serializable
 * transaction, state transition and replay — over the batch's one range,
 * under a key derived from the batch and the component. The components
 * never read each other's results, and their commissions stay their own:
 * nothing is merged, netted or capped across them. They must all be funded
 * from the one source account the batch names.
 *
 * The batch is not one transaction: each component commits its own run, so
 * a stateful component keeps its own isolation. A component that fails
 * leaves the batch `open`, the runs before it standing; calling again with
 * the same request resumes it — runs already linked are kept, a run
 * committed but not yet linked is found again under its key, and the rest
 * are calculated. The batch is `completed` once every component's run is
 * linked, and until then none of its commissions is posted. A completed
 * batch is returned as stored. The same key with another request is
 * refused.
 *
 * It moves no money and reverses nothing: corrections stay with their own
 * engines.
 */
final readonly class HybridCalculationEngine
{
    private const BATCHES = 'mlm_calculation_batches';

    private const ITEMS = 'mlm_calculation_batch_items';

    public function __construct(private CalculationEngine $engine) {}

    /**
     * @throws InvalidHybridCalculation for a version that is not a hybrid composition, or a source account that does not fund it
     * @throws ConflictingCalculationBatch for a key already used for another request
     * @throws CorruptCalculationBatch for stored items or links no supported write produces
     * @throws InvalidCalculationRun|ConflictingCalculationReplay|InvalidCommissionCandidate from a component's calculation, the batch left open
     */
    public function calculate(PlanVersion $planVersion, CalculationContext $context, LedgerAccount $sourceAccount): HybridCalculationResult
    {
        $db = $planVersion->getConnection();

        if ($db->transactionLevel() > 0) {
            throw InvalidHybridCalculation::insideTransaction((string) $db->getName());
        }

        $batch = $this->open($db, (string) $planVersion->getKey(), $context, (string) $sourceAccount->getKey());

        if ($batch->status === CalculationBatchStatus::Open) {
            foreach ($this->items($db, $batch) as $item) {
                if ($item->calculation_run_id !== null) {
                    continue;
                }

                // Committed on its own; a replay under the same key returns
                // the run a crash left unlinked, and applies nothing again.
                $run = $this->engine->calculate($item->component, new CalculationContext($batch->from_at, $batch->until_at, $item->child_idempotency_key));

                $this->link($db, $batch, $item, $run);
            }

            $batch = $this->complete($db, $batch);
        }

        return $this->result($db, $batch);
    }

    /**
     * The key a component's run is calculated under: the batch's and the
     * component's ids, so it never changes between attempts.
     */
    public static function childKey(string $batch, string $component): string
    {
        return "hybrid:{$batch}:{$component}";
    }

    /**
     * The batch under the request's key — checked to be the same request,
     * with the same items — or a new one with its items. One short
     * transaction under the program's lock, taken before anything else is
     * read in it, so two requests under one key never both create a batch,
     * and each sees what the other committed.
     */
    private function open(Connection $db, string $versionId, CalculationContext $context, string $accountId): CalculationBatch
    {
        $connection = (string) $db->getName();
        $version = PlanVersion::on($connection)->find($versionId) ?? throw InvalidCalculationRun::missing('plan version', $versionId);
        $plan = Plan::on($connection)->find($version->plan_id) ?? throw InvalidCalculationRun::missing('plan', $version->plan_id);
        $components = $this->components($connection, $versionId);

        return $db->transaction(function () use ($db, $connection, $version, $plan, $components, $context, $accountId): CalculationBatch {
            $program = Program::on($connection)->whereKey($plan->program_id)->lockForUpdate()->first()
                ?? throw InvalidCalculationRun::missing('program', $plan->program_id);

            $existing = CalculationBatch::on($connection)
                ->where('program_id', $program->getKey())
                ->where('idempotency_key', $context->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->assertSameRequest($existing, (string) $version->getKey(), $context, $accountId);
                $this->assertItems($connection, $existing, $components);

                return $existing;
            }

            if ($version->status === PlanVersionStatus::Draft) {
                throw InvalidHybridCalculation::draft((string) $version->getKey());
            }

            if ($components->count() < 2) {
                throw InvalidHybridCalculation::tooFewComponents((string) $version->getKey(), $components->count());
            }

            $this->assertFunds($connection, $program, $accountId, $components);

            $batch = new CalculationBatch;
            $id = $batch->newUniqueId();
            $now = $batch->freshTimestamp();

            $db->table(self::BATCHES)->insert([
                'id' => $id,
                'program_id' => $program->getKey(),
                'plan_version_id' => $version->getKey(),
                'source_ledger_account_id' => $accountId,
                'idempotency_key' => $context->idempotencyKey,
                'from_at' => $context->from,
                'until_at' => $context->until,
                'status' => CalculationBatchStatus::Open->value,
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $db->table(self::ITEMS)->insert($components->values()->map(static fn (PlanComponent $component, int $index): array => [
                'id' => (new CalculationBatchItem)->newUniqueId(),
                'calculation_batch_id' => $id,
                'plan_component_id' => $component->getKey(),
                'position' => $index + 1,
                'child_idempotency_key' => self::childKey($id, (string) $component->getKey()),
                'calculation_run_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            return CalculationBatch::on($connection)->findOrFail($id);
        });
    }

    /**
     * The version's commission components, in the order they compose: by
     * position, then id.
     *
     * @return Collection<int, PlanComponent>
     */
    private function components(string $connection, string $versionId): Collection
    {
        return PlanComponent::on($connection)
            ->where('plan_version_id', $versionId)
            ->where('driver', CommissionComponentDriver::KEY)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    private function assertSameRequest(CalculationBatch $batch, string $versionId, CalculationContext $context, string $accountId): void
    {
        $stored = [
            'plan version' => $batch->plan_version_id,
            'from' => $batch->from_at->format('Y-m-d H:i:s'),
            'until' => $batch->until_at->format('Y-m-d H:i:s'),
            'source account' => $batch->source_ledger_account_id,
        ];

        $requested = [
            'plan version' => $versionId,
            'from' => $context->from->format('Y-m-d H:i:s'),
            'until' => $context->until->format('Y-m-d H:i:s'),
            'source account' => $accountId,
        ];

        $conflicts = array_keys(array_diff_assoc($requested, $stored));

        if ($conflicts !== []) {
            throw ConflictingCalculationBatch::forKey($batch, $conflicts);
        }
    }

    /**
     * The batch's items are exactly its version's commission components,
     * in their order, under their derived keys: nothing added, removed or
     * reordered since the batch was made.
     *
     * @param  Collection<int, PlanComponent>  $components
     */
    private function assertItems(string $connection, CalculationBatch $batch, Collection $components): void
    {
        $expected = $components->values()->map(static fn (PlanComponent $component, int $index): string => sprintf(
            '%d %s %s',
            $index + 1,
            $component->getKey(),
            self::childKey((string) $batch->getKey(), (string) $component->getKey()),
        ))->all();

        $stored = CalculationBatchItem::on($connection)
            ->where('calculation_batch_id', $batch->getKey())
            ->orderBy('position')
            ->get()
            ->map(static fn (CalculationBatchItem $item): string => "{$item->position} {$item->plan_component_id} {$item->child_idempotency_key}")
            ->all();

        if ($stored !== $expected) {
            throw CorruptCalculationBatch::components((string) $batch->getKey(), sprintf('it holds %d item(s) where the version has %d commission component(s), or they differ in component, order or key', count($stored), count($expected)));
        }
    }

    /**
     * The source account is a system account of the program, and every
     * component is funded from it: its own source account and currency are
     * this account's.
     *
     * @param  Collection<int, PlanComponent>  $components
     */
    private function assertFunds(string $connection, Program $program, string $accountId, Collection $components): void
    {
        $problem = CommissionFunding::problem($connection, (string) $program->getKey(), $accountId, $components);

        if ($problem !== null) {
            throw InvalidHybridCalculation::sourceAccount($accountId, $problem);
        }
    }

    /**
     * The batch's items in order, each with its component, and the run of
     * each linked one — checked to be that component's run in this batch.
     *
     * @return Collection<int, CalculationBatchItem>
     */
    private function items(Connection $db, CalculationBatch $batch): Collection
    {
        $items = CalculationBatchItem::on((string) $db->getName())
            ->where('calculation_batch_id', $batch->getKey())
            ->orderBy('position')
            ->with(['component', 'run'])
            ->get()
            ->toBase();

        foreach ($items as $item) {
            if ($item->calculation_run_id !== null) {
                $this->assertRun($batch, $item, $item->run ?? throw CorruptCalculationBatch::run((string) $batch->getKey(), (string) $item->getKey(), $item->calculation_run_id, 'does not exist'));
            }
        }

        return $items;
    }

    /**
     * The run is the item's: its component, calculated under its key, in
     * the batch's program and version, over the batch's range, from the
     * batch's source account.
     */
    private function assertRun(CalculationBatch $batch, CalculationBatchItem $item, CalculationRun $run): void
    {
        $problem = match (true) {
            $run->plan_component_id !== $item->plan_component_id => 'belongs to another component',
            $run->idempotency_key !== $item->child_idempotency_key => 'was not calculated under the item\'s key',
            $run->program_id !== $batch->program_id, $run->plan_version_id !== $batch->plan_version_id => 'belongs to another program or plan version',
            ! $run->from_at->equalTo($batch->from_at), ! $run->until_at->equalTo($batch->until_at) => 'covers another range',
            $run->source_ledger_account_id !== $batch->source_ledger_account_id => 'is funded from another account',
            default => null,
        };

        if ($problem !== null) {
            throw CorruptCalculationBatch::run((string) $batch->getKey(), (string) $item->getKey(), (string) $run->getKey(), $problem);
        }
    }

    /**
     * Links the item to its run, once: a link already there must be the
     * same run, and is never replaced.
     */
    private function link(Connection $db, CalculationBatch $batch, CalculationBatchItem $item, CalculationRun $run): void
    {
        $this->assertRun($batch, $item, $run);

        $db->transaction(static function () use ($db, $batch, $item, $run): void {
            $linked = $db->table(self::ITEMS)->where('id', $item->getKey())->lockForUpdate()->value('calculation_run_id');

            if ($linked === null) {
                $db->table(self::ITEMS)->where('id', $item->getKey())->whereNull('calculation_run_id')->update([
                    'calculation_run_id' => $run->getKey(),
                    'updated_at' => $run->freshTimestamp(),
                ]);
            } elseif ((string) $linked !== $run->getKey()) {
                throw CorruptCalculationBatch::run((string) $batch->getKey(), (string) $item->getKey(), (string) $linked, "is not the run calculated under its key [{$run->getKey()}]");
            }
        });
    }

    /**
     * Completes the batch, once — only when every item has its run.
     */
    private function complete(Connection $db, CalculationBatch $batch): CalculationBatch
    {
        return $db->transaction(static function () use ($db, $batch): CalculationBatch {
            $current = CalculationBatch::on((string) $db->getName())->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status === CalculationBatchStatus::Completed) {
                return $current;
            }

            $unlinked = $db->table(self::ITEMS)->where('calculation_batch_id', $current->getKey())->whereNull('calculation_run_id')->count();

            if ($unlinked > 0) {
                throw CorruptCalculationBatch::components((string) $current->getKey(), "{$unlinked} item(s) have no run, so it cannot be completed");
            }

            $now = $current->freshTimestamp();

            $db->table(self::BATCHES)->where('id', $current->getKey())->where('status', CalculationBatchStatus::Open->value)->update([
                'status' => CalculationBatchStatus::Completed->value,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

            return CalculationBatch::on((string) $db->getName())->findOrFail($current->getKey());
        });
    }

    private function result(Connection $db, CalculationBatch $batch): HybridCalculationResult
    {
        $items = $this->items($db, $batch);

        foreach ($items as $item) {
            if ($item->calculation_run_id === null) {
                throw CorruptCalculationBatch::components((string) $batch->getKey(), "it is completed, yet item [{$item->getKey()}] has no run");
            }
        }

        $commissions = Commission::on((string) $db->getName())
            ->whereIn('calculation_run_id', $items->pluck('calculation_run_id')->all())
            ->orderBy('candidate_key')
            ->get()
            ->groupBy('calculation_run_id')
            ->map(static fn (Collection $commissions): array => $commissions->values()->all())
            ->all();

        return new HybridCalculationResult($batch, $items->values()->all(), $commissions);
    }
}
