<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Period;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use PandaBear\Mlm\Calculation\CalculationContext;
use PandaBear\Mlm\Calculation\CalculationEngine;
use PandaBear\Mlm\Commission\CommissionComponentDriver;
use PandaBear\Mlm\Commission\HybridCalculationEngine;
use PandaBear\Mlm\Exceptions\CorruptCommissionPeriod;
use PandaBear\Mlm\Exceptions\InvalidCommissionPeriod;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Models\CommissionPeriodRun;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanVersion;

/**
 * Calculates a commission period (ADR-029): every commission component of
 * its plan version, over its range, funded from its source account — one
 * component through `CalculationEngine`, two or more through
 * `HybridCalculationEngine` — and records which run calculated which
 * component, relationally. Nothing of either engine is repeated here.
 *
 * Calculating begins by closing the period's input, under an exclusive
 * lock of its row: from then on no new business entry falls in its range
 * (`FinalizedCommissionPeriodGuard`), so every component — and a resumed
 * calculation — reads the same input. The components then calculate in
 * transactions of their own, never one around them all; a failure leaves
 * the period open, its input closed, and calling again resumes it under
 * the same derived keys: `period:<period>:<component>` for one component,
 * `period:<period>:hybrid` for the batch. The period is `calculated` once
 * every component's run is linked. A calculated, finalized or released
 * period is returned as stored.
 */
final readonly class CommissionPeriodCalculator
{
    private const PERIODS = 'mlm_commission_periods';

    private const RUNS = 'mlm_commission_period_runs';

    public function __construct(
        private CalculationEngine $engine,
        private HybridCalculationEngine $hybrid,
    ) {}

    /**
     * @throws InvalidCommissionPeriod for a version with no commission component, or a caller's open transaction
     * @throws CorruptCommissionPeriod for stored facts or links no supported write produces
     */
    public function calculate(CommissionPeriod $period): CommissionPeriodResult
    {
        $db = $period->getConnection();

        if ($db->transactionLevel() > 0) {
            throw InvalidCommissionPeriod::insideTransaction((string) $db->getName());
        }

        $period = $this->stored($db, (string) $period->getKey());
        $components = $this->components($db, $period);

        if ($period->status !== CommissionPeriodStatus::Open) {
            return $this->result($db, $period, $components);
        }

        if ($components->isEmpty()) {
            throw InvalidCommissionPeriod::noCommissionComponents((string) $period->getKey(), $period->plan_version_id);
        }

        $period = $this->closeInput($db, $period);

        if ($period->status === CommissionPeriodStatus::Open) {
            if ($components->count() === 1) {
                $component = $components->first();

                if (! $this->linked($db, $period, $component)) {
                    $run = $this->engine->calculate($component, $this->context($period, CommissionPeriodMembership::childKey((string) $period->getKey(), (string) $component->getKey())));
                    $this->link($db, $period, $component, 1, $run);
                }
            } else {
                $batch = $this->hybrid->calculate(
                    PlanVersion::on((string) $db->getName())->findOrFail($period->plan_version_id),
                    $this->context($period, CommissionPeriodMembership::hybridKey((string) $period->getKey())),
                    LedgerAccount::on((string) $db->getName())->findOrFail($period->source_ledger_account_id),
                );

                foreach ($batch->items as $item) {
                    $this->link($db, $period, $item->component, $item->position, $item->run ?? throw CorruptCommissionPeriod::because((string) $period->getKey(), 'its hybrid batch completed without a run'));
                }
            }

            $period = $this->complete($db, $period, $components);
        }

        return $this->result($db, $period, $components);
    }

    /**
     * The period as stored, its plan version and source account checked to
     * be its program's.
     */
    private function stored(Connection $db, string $id): CommissionPeriod
    {
        $connection = (string) $db->getName();
        $period = CommissionPeriod::on($connection)->findOrFail($id);
        $version = PlanVersion::on($connection)->find($period->plan_version_id);

        if ($version === null || Plan::on($connection)->whereKey($version->plan_id)->value('program_id') !== $period->program_id) {
            throw CorruptCommissionPeriod::because($id, "its plan version [{$period->plan_version_id}] is not its program's");
        }

        if (LedgerAccount::on($connection)->whereKey($period->source_ledger_account_id)->value('program_id') !== $period->program_id) {
            throw CorruptCommissionPeriod::because($id, "its source account [{$period->source_ledger_account_id}] is not its program's");
        }

        return $period;
    }

    /**
     * @return Collection<int, PlanComponent> by position, then id
     */
    private function components(Connection $db, CommissionPeriod $period): Collection
    {
        return PlanComponent::on((string) $db->getName())
            ->where('plan_version_id', $period->plan_version_id)
            ->where('driver', CommissionComponentDriver::KEY)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    private function context(CommissionPeriod $period, string $key): CalculationContext
    {
        return new CalculationContext($period->from_at, $period->until_at, $key);
    }

    /**
     * Closes the period's input, once: every entry already recorded in its
     * range has committed when the exclusive lock is granted, and none can
     * be recorded there after.
     */
    private function closeInput(Connection $db, CommissionPeriod $period): CommissionPeriod
    {
        return $db->transaction(static function () use ($db, $period): CommissionPeriod {
            $current = CommissionPeriod::on((string) $db->getName())->whereKey($period->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status === CommissionPeriodStatus::Open && $current->input_closed_at === null) {
                $now = $current->freshTimestamp();
                $db->table(self::PERIODS)->where('id', $current->getKey())->whereNull('input_closed_at')->update(['input_closed_at' => $now, 'updated_at' => $now]);

                return CommissionPeriod::on((string) $db->getName())->findOrFail($current->getKey());
            }

            return $current;
        });
    }

    private function linked(Connection $db, CommissionPeriod $period, PlanComponent $component): bool
    {
        return $db->table(self::RUNS)->where('commission_period_id', $period->getKey())->where('plan_component_id', $component->getKey())->exists();
    }

    /**
     * Links the component's run, once: an existing link must be the same
     * run, and is never replaced.
     */
    private function link(Connection $db, CommissionPeriod $period, PlanComponent $component, int $position, CalculationRun $run): void
    {
        $this->assertRun($period, (string) $component->getKey(), $run);

        $db->transaction(static function () use ($db, $period, $component, $position, $run): void {
            $db->table(self::PERIODS)->where('id', $period->getKey())->lockForUpdate()->value('id');
            $linked = $db->table(self::RUNS)->where('commission_period_id', $period->getKey())->where('plan_component_id', $component->getKey())->value('calculation_run_id');

            if ($linked === null) {
                $now = $run->freshTimestamp();
                $db->table(self::RUNS)->insert([
                    'id' => (new CommissionPeriodRun)->newUniqueId(),
                    'commission_period_id' => $period->getKey(),
                    'plan_component_id' => $component->getKey(),
                    'calculation_run_id' => $run->getKey(),
                    'position' => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } elseif ((string) $linked !== $run->getKey()) {
                throw CorruptCommissionPeriod::because((string) $period->getKey(), "component [{$component->getKey()}] is linked to run [{$linked}], not the run calculated for it [{$run->getKey()}]");
            }
        });
    }

    /**
     * The run is the component's, over the period's range, in its program
     * and version, from its source account.
     */
    private function assertRun(CommissionPeriod $period, string $componentId, CalculationRun $run): void
    {
        $problem = match (true) {
            $run->plan_component_id !== $componentId => 'belongs to another component',
            $run->program_id !== $period->program_id, $run->plan_version_id !== $period->plan_version_id => 'belongs to another program or plan version',
            ! $run->from_at->equalTo($period->from_at), ! $run->until_at->equalTo($period->until_at) => 'covers another range',
            $run->source_ledger_account_id !== $period->source_ledger_account_id => 'is funded from another account',
            default => null,
        };

        if ($problem !== null) {
            throw CorruptCommissionPeriod::because((string) $period->getKey(), "run [{$run->getKey()}] {$problem}");
        }
    }

    /**
     * Marks the period calculated, once — only when every component has its
     * run.
     *
     * @param  Collection<int, PlanComponent>  $components
     */
    private function complete(Connection $db, CommissionPeriod $period, Collection $components): CommissionPeriod
    {
        return $db->transaction(function () use ($db, $period, $components): CommissionPeriod {
            $current = CommissionPeriod::on((string) $db->getName())->whereKey($period->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status !== CommissionPeriodStatus::Open) {
                return $current;
            }

            $this->runs($db, $current, $components);
            $now = $current->freshTimestamp();

            $db->table(self::PERIODS)->where('id', $current->getKey())->where('status', CommissionPeriodStatus::Open->value)->update([
                'status' => CommissionPeriodStatus::Calculated->value,
                'calculated_at' => $now,
                'updated_at' => $now,
            ]);

            return CommissionPeriod::on((string) $db->getName())->findOrFail($current->getKey());
        });
    }

    /**
     * The period's runs, checked to be exactly one per commission
     * component, in their order, each that component's run.
     *
     * @param  Collection<int, PlanComponent>  $components
     * @return Collection<int, CommissionPeriodRun>
     */
    private function runs(Connection $db, CommissionPeriod $period, Collection $components): Collection
    {
        $runs = CommissionPeriodRun::on((string) $db->getName())
            ->where('commission_period_id', $period->getKey())
            ->orderBy('position')
            ->with(['component', 'run'])
            ->get()
            ->toBase();

        $expected = $components->values()->map(static fn (PlanComponent $component, int $index): string => ($index + 1).' '.$component->getKey())->all();
        $stored = $runs->map(static fn (CommissionPeriodRun $run): string => "{$run->position} {$run->plan_component_id}")->all();

        if ($stored !== $expected) {
            throw CorruptCommissionPeriod::because((string) $period->getKey(), sprintf('it links %d run(s) where its plan version has %d commission component(s), or they differ in component or order', count($stored), count($expected)));
        }

        foreach ($runs as $run) {
            $this->assertRun($period, $run->plan_component_id, $run->run ?? throw CorruptCommissionPeriod::because((string) $period->getKey(), "run [{$run->calculation_run_id}] does not exist"));
        }

        return $runs;
    }

    /**
     * @param  Collection<int, PlanComponent>  $components
     */
    private function result(Connection $db, CommissionPeriod $period, Collection $components): CommissionPeriodResult
    {
        $runs = $this->runs($db, $period, $components);

        $commissions = Commission::on((string) $db->getName())
            ->whereIn('calculation_run_id', $runs->pluck('calculation_run_id')->all())
            ->orderBy('candidate_key')
            ->get()
            ->groupBy('calculation_run_id')
            ->map(static fn (Collection $commissions): array => $commissions->values()->all())
            ->all();

        return new CommissionPeriodResult($period, $runs->values()->all(), $commissions);
    }
}
