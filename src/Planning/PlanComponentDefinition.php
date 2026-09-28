<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning;

/**
 * A component as its driver sees it: read-only data, never a model. Its
 * rules are in order — by position, then id — and already parsed.
 */
final readonly class PlanComponentDefinition
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  list<PlanRuleDefinition>  $rules
     */
    public function __construct(
        public string $key,
        public string $driver,
        public string $name,
        public array $parameters,
        public int $position,
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
