<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning;

use PandaBear\Mlm\Planning\Rules\RuleDefinition;

/**
 * A rule as its component's driver sees it: read-only data, never a model.
 */
final readonly class PlanRuleDefinition
{
    public function __construct(
        public string $key,
        public string $name,
        public int $position,
        public RuleDefinition $definition,
    ) {}
}
