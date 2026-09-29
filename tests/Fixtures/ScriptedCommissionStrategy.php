<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Fixtures;

use Closure;
use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionStrategy;
use PandaBear\Mlm\Commission\CommissionStrategyDefinition;

/**
 * A strategy whose output a test scripts: whatever `$script` yields —
 * candidates, anything else, or an exception.
 */
final class ScriptedCommissionStrategy implements CommissionStrategy
{
    public int $calculations = 0;

    /**
     * @var (Closure(CommissionCalculationContext): iterable<mixed>)|null
     */
    public ?Closure $script = null;

    public function key(): string
    {
        return 'test.scripted';
    }

    public function validate(CommissionStrategyDefinition $definition): void {}

    public function calculate(CommissionCalculationContext $context): iterable
    {
        $this->calculations++;

        return $this->script === null ? [] : ($this->script)($context);
    }
}
