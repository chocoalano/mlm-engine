<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use PandaBear\Mlm\Models\CalculationBatch;
use PandaBear\Mlm\Models\CalculationBatchItem;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\PlanComponent;

/**
 * A completed hybrid calculation (ADR-028): its batch, and for each
 * commission component, in the batch's order, the component, its run and
 * that run's commissions — kept apart, never merged or netted.
 */
final readonly class HybridCalculationResult
{
    /**
     * @param  list<CalculationBatchItem>  $items  in position order, each with its component and run
     * @param  array<string, list<Commission>>  $commissions  by calculation run id, in candidate key order
     */
    public function __construct(
        public CalculationBatch $batch,
        public array $items,
        private array $commissions,
    ) {}

    /**
     * @return list<PlanComponent> in the batch's order
     */
    public function components(): array
    {
        return array_map(static fn (CalculationBatchItem $item): PlanComponent => $item->component, $this->items);
    }

    /**
     * @return list<CalculationRun> one per component, in the batch's order
     */
    public function runs(): array
    {
        return array_map(static fn (CalculationBatchItem $item): CalculationRun => $item->run ?? throw new \LogicException("Item [{$item->getKey()}] has no run."), $this->items);
    }

    /**
     * The commissions one component's run found, in candidate key order.
     *
     * @return list<Commission>
     */
    public function commissionsOf(CalculationRun|PlanComponent $of): array
    {
        foreach ($this->items as $item) {
            if ($of->is($of instanceof PlanComponent ? $item->component : $item->run)) {
                return $this->commissions[(string) $item->calculation_run_id] ?? [];
            }
        }

        return [];
    }

    /**
     * Every commission of the batch: each run's, in the batch's order.
     *
     * @return list<Commission>
     */
    public function commissions(): array
    {
        return array_merge(...array_map(fn (CalculationBatchItem $item): array => $this->commissions[(string) $item->calculation_run_id] ?? [], $this->items));
    }
}
