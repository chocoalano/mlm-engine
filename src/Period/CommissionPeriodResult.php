<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Period;

use LogicException;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Models\CommissionPeriodRun;
use PandaBear\Mlm\Models\PlanComponent;

/**
 * A calculated commission period (ADR-029): the period, and for each
 * commission component, in the period's order, the component, its run and
 * that run's commissions — kept apart, never merged or totalled.
 */
final readonly class CommissionPeriodResult
{
    /**
     * @param  list<CommissionPeriodRun>  $runs  in position order, each with its component and run
     * @param  array<string, list<Commission>>  $commissions  by calculation run id, in candidate key order
     */
    public function __construct(
        public CommissionPeriod $period,
        public array $runs,
        private array $commissions,
    ) {}

    /**
     * @return list<PlanComponent>
     */
    public function components(): array
    {
        return array_map(static fn (CommissionPeriodRun $run): PlanComponent => $run->component, $this->runs);
    }

    /**
     * @return list<CalculationRun>
     */
    public function calculationRuns(): array
    {
        return array_map(static fn (CommissionPeriodRun $run): CalculationRun => $run->run ?? throw new LogicException("Period run [{$run->getKey()}] has no run."), $this->runs);
    }

    /**
     * @return list<Commission>
     */
    public function commissionsOf(CalculationRun|PlanComponent $of): array
    {
        foreach ($this->runs as $run) {
            if ($of->is($of instanceof PlanComponent ? $run->component : $run->run)) {
                return $this->commissions[$run->calculation_run_id] ?? [];
            }
        }

        return [];
    }

    /**
     * @return list<Commission> each run's, in the period's order
     */
    public function commissions(): array
    {
        return array_merge(...array_map(fn (CommissionPeriodRun $run): array => $this->commissions[$run->calculation_run_id] ?? [], $this->runs));
    }
}
