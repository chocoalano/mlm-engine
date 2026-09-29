<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use PandaBear\Mlm\Finance\CurrencyCode;
use PandaBear\Mlm\Planning\PlanRuleDefinition;

/**
 * A commission component as its strategy sees it: read-only data from one
 * validated plan version, never a model. How its rules combine is the
 * strategy's own decision; the package gives them no meaning.
 */
final readonly class CommissionStrategyDefinition
{
    /**
     * @param  array<string, mixed>  $parameters  the strategy's own inert configuration
     * @param  list<PlanRuleDefinition>  $rules  in order: by position, then id
     */
    public function __construct(
        public string $planVersionId,
        public int $planVersion,
        public string $componentKey,
        public string $componentName,
        public string $strategy,
        public CurrencyCode $currency,
        public string $sourceAccount,
        public array $parameters,
        public array $rules,
    ) {}

    public function rule(string $key): ?PlanRuleDefinition
    {
        foreach ($this->rules as $rule) {
            if ($rule->key === $key) {
                return $rule;
            }
        }

        return null;
    }
}
