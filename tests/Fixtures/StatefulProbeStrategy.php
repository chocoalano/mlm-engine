<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Fixtures;

use Closure;
use Illuminate\Database\Connection;
use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionStateTransition;
use PandaBear\Mlm\Commission\CommissionStrategyDefinition;
use PandaBear\Mlm\Commission\StatefulCommissionCalculation;
use PandaBear\Mlm\Commission\StatefulCommissionStrategy;
use PandaBear\Mlm\Models\CalculationRun;

/**
 * A stateful strategy a test scripts: the candidates `$script` yields, and
 * the transition `$apply` performs — counting how often each runs.
 */
final class StatefulProbeStrategy implements StatefulCommissionStrategy
{
    public int $calculations = 0;

    public int $applications = 0;

    /**
     * @var (Closure(CommissionCalculationContext): iterable<mixed>)|null
     */
    public ?Closure $script = null;

    /**
     * @var (Closure(Connection, CalculationRun, int): void)|null given the attempt's number
     */
    public ?Closure $apply = null;

    public ?CommissionCalculationContext $context = null;

    public function key(): string
    {
        return 'test.stateful';
    }

    public function validate(CommissionStrategyDefinition $definition): void {}

    public function calculate(CommissionCalculationContext $context): iterable
    {
        return $this->calculateStateful($context)->candidates;
    }

    public function calculateStateful(CommissionCalculationContext $context): StatefulCommissionCalculation
    {
        $this->calculations++;
        $this->context = $context;
        $candidates = $this->script === null ? [] : ($this->script)($context);
        $probe = $this;

        return new StatefulCommissionCalculation($candidates, new class($probe) implements CommissionStateTransition
        {
            public function __construct(private StatefulProbeStrategy $probe) {}

            public function apply(Connection $connection, CalculationRun $run): void
            {
                $this->probe->applications++;

                if ($this->probe->apply !== null) {
                    ($this->probe->apply)($connection, $run, $this->probe->calculations);
                }
            }
        });
    }
}
