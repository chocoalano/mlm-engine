<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Pairing;

use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionStrategyDefinition;
use PandaBear\Mlm\Commission\StatefulCommissionCalculation;
use PandaBear\Mlm\Commission\StatefulCommissionStrategy;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;

/**
 * `binary.pairing.proportional` (ADR-023): every binary member earns the
 * quantity its pairs consumed from each leg times `unit_amount`, rounded
 * once by `rounding` (ADR-020). Pairs whose award rounds to nothing still
 * consume carry, and earn no commission.
 *
 * Stateful: carry left unpaired stays for the component's next run, source
 * by source, and each run starts where the last ended. Pairing happens once,
 * at the run's close, and a commission is earned at `until`. Through
 * `calculate()` it only previews; the calculation engine commits the state.
 * Reads the binary tree as it stood when each activity happened — never
 * generic placement or sponsorship. Rules are not supported.
 */
final readonly class BinaryPairingProportionalStrategy implements StatefulCommissionStrategy
{
    public const KEY = 'binary.pairing.proportional';

    public function __construct(private BinaryPairingCalculator $calculator) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function validate(CommissionStrategyDefinition $definition): void
    {
        if ($definition->rules !== []) {
            throw InvalidPlanDefinition::input(self::KEY.' rules', 'this strategy takes no rules; remove them rather than have them ignored.');
        }

        BinaryPairingParameters::proportional(self::KEY, $definition->parameters);
    }

    public function calculate(CommissionCalculationContext $context): iterable
    {
        return $this->calculateStateful($context)->candidates;
    }

    public function calculateStateful(CommissionCalculationContext $context): StatefulCommissionCalculation
    {
        return $this->calculator->calculate(self::KEY, $context, BinaryPairingParameters::proportional(self::KEY, $context->definition->parameters));
    }
}
