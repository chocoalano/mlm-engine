<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning\Rules;

use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;

/**
 * One or more conditions or nested groups, combined by `all` or `any`.
 */
final readonly class RuleGroup implements RuleNode
{
    /**
     * @param  list<RuleNode>  $children
     */
    private function __construct(
        public RuleCombinator $match,
        public array $children,
    ) {}

    public static function all(RuleNode ...$children): self
    {
        return self::of(RuleCombinator::All, array_values($children), 'group');
    }

    public static function any(RuleNode ...$children): self
    {
        return self::of(RuleCombinator::Any, array_values($children), 'group');
    }

    /**
     * @internal
     *
     * @param  list<RuleNode>  $children
     */
    public static function of(RuleCombinator $match, array $children, string $at): self
    {
        if ($children === []) {
            throw InvalidRuleDefinition::at("{$at}.children", 'a group has at least one child.');
        }

        return new self($match, $children);
    }

    public function toArray(): array
    {
        return [
            'type' => 'group',
            'match' => $this->match->value,
            'children' => array_map(static fn (RuleNode $child): array => $child->toArray(), $this->children),
        ];
    }
}
