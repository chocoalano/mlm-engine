<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning\Rules;

/**
 * A node of a rule tree: a `RuleGroup` or a `MetricCondition`. There are no
 * others.
 */
interface RuleNode
{
    /**
     * The node's canonical array form, as it is stored.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
