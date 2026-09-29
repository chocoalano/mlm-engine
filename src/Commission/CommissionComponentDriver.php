<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Planning\PlanComponentDefinition;
use PandaBear\Mlm\Planning\PlanComponentDriver;

/**
 * The package's commission component (ADR-018): a plan component that
 * selects a registered `CommissionStrategy` and configures it. Its
 * parameters are exactly:
 *
 * - `strategy`: the strategy's registry key;
 * - `currency`: the currency its commissions are paid in;
 * - `source_account`: the key of the program's system ledger account its
 *   commissions are posted from;
 * - `parameters`: the strategy's own inert configuration.
 *
 * Its rules belong to the strategy, which alone decides what they mean.
 * When the version is validated this driver checks the four parameters,
 * requires the strategy to be registered, and lets the strategy judge the
 * rest. Rules and metrics are checked by the generic validator, not again.
 * `CalculationEngine` calculates the component.
 */
final readonly class CommissionComponentDriver implements PlanComponentDriver
{
    public const KEY = 'commission.strategy';

    public function __construct(private CommissionStrategyRegistry $strategies) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function validate(PlanComponentDefinition $component): void
    {
        $definition = $this->definition($component);

        if (! $this->strategies->has($definition->strategy)) {
            throw InvalidPlanDefinition::input('commission component', "no commission strategy is registered under \"{$definition->strategy}\".");
        }

        $this->strategies->get($definition->strategy)->validate($definition);
    }

    /**
     * @internal
     *
     * The component as its strategy sees it; its parameters checked.
     *
     * @throws InvalidPlanDefinition
     */
    public function definition(PlanComponentDefinition $component): CommissionStrategyDefinition
    {
        if ($component->driver !== self::KEY) {
            throw InvalidPlanDefinition::input('commission component', sprintf('its driver is "%s", not "%s".', $component->driver, self::KEY));
        }

        if ($component->planVersionId === null || $component->planVersion === null) {
            throw InvalidPlanDefinition::input('commission component', 'it is judged outside a stored plan version.');
        }

        $parameters = CommissionComponentParameters::parse($component->parameters);

        return new CommissionStrategyDefinition(
            planVersionId: $component->planVersionId,
            planVersion: $component->planVersion,
            componentKey: $component->key,
            componentName: $component->name,
            strategy: $parameters->strategy,
            currency: $parameters->currency,
            sourceAccount: $parameters->sourceAccount,
            parameters: $parameters->parameters,
            rules: $component->rules,
        );
    }
}
