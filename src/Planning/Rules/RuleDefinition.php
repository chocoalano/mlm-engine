<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning\Rules;

use JsonException;
use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;

/**
 * A rule's condition tree, in the safe rule language: groups (`all`, `any`)
 * of metric conditions and nested groups, under one root group.
 *
 *   RuleDefinition::all(
 *       MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100'),
 *       RuleGroup::any(
 *           MetricCondition::of('sponsor.network.volume', ['type' => 'sales', 'max_depth' => 3], '>=', '500'),
 *           MetricCondition::of('placement.network.volume', ['type' => 'sales'], 'between', '400', '900'),
 *       ),
 *   );
 *
 * Stored as JSON, and read back only through `fromArray()` / `fromJson()`,
 * which accept exactly the nodes and fields the language has — anything
 * else, including an unknown field, is refused rather than ignored. There
 * is no expression, formula, function, class name or SQL anywhere in it.
 *
 * This is configuration. Nothing here evaluates a rule for a member.
 *
 * Size limits guard against a pathological definition, not business rules:
 * the root is at depth 1 and no node lies deeper than `MAX_DEPTH`; a tree
 * has at most `MAX_NODES` groups and conditions.
 */
final readonly class RuleDefinition
{
    public const MAX_DEPTH = 16;

    public const MAX_NODES = 500;

    private const GROUP_FIELDS = ['children', 'match', 'type'];

    private const CONDITION_FIELDS = ['metric', 'operands', 'operator', 'parameters', 'type'];

    private function __construct(public RuleGroup $root) {}

    public static function all(RuleNode ...$children): self
    {
        return self::of(RuleGroup::all(...$children));
    }

    public static function any(RuleNode ...$children): self
    {
        return self::of(RuleGroup::any(...$children));
    }

    /**
     * @throws InvalidRuleDefinition when the tree is deeper or larger than the limits
     */
    public static function of(RuleGroup $root): self
    {
        $nodes = 0;
        self::measure($root, 'root', 1, $nodes);

        return new self($root);
    }

    /**
     * A definition from its array form — as stored, or as an application
     * sends it.
     *
     * @throws InvalidRuleDefinition
     */
    public static function fromArray(mixed $definition): self
    {
        $nodes = 0;
        $root = self::node($definition, 'root', 1, $nodes);

        if (! $root instanceof RuleGroup) {
            throw InvalidRuleDefinition::at('root', 'the root is a group — "all" or "any" — not a single condition.');
        }

        return new self($root);
    }

    /**
     * @throws InvalidRuleDefinition
     */
    public static function fromJson(mixed $json): self
    {
        if (! is_string($json)) {
            throw InvalidRuleDefinition::at('root', 'a stored rule definition is JSON text.');
        }

        try {
            // Deep enough for any tree within the limits, and no deeper.
            $definition = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidRuleDefinition::at('root', 'it is not valid JSON, or nests too deeply: '.$exception->getMessage());
        }

        return self::fromArray($definition);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->root->toArray();
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Every condition, in tree order, keyed by its path — "root.children[1]"
     * — so a message or a trace can point at it.
     *
     * @return array<string, MetricCondition>
     */
    public function conditions(): array
    {
        $conditions = [];
        self::collect($this->root, 'root', $conditions);

        return $conditions;
    }

    /**
     * @param  array<string, MetricCondition>  $conditions
     */
    private static function collect(RuleNode $node, string $at, array &$conditions): void
    {
        if ($node instanceof MetricCondition) {
            $conditions[$at] = $node;

            return;
        }

        if ($node instanceof RuleGroup) {
            foreach ($node->children as $index => $child) {
                self::collect($child, "{$at}.children[{$index}]", $conditions);
            }
        }
    }

    private static function measure(RuleNode $node, string $at, int $depth, int &$nodes): void
    {
        self::count($at, $depth, $nodes);

        if ($node instanceof RuleGroup) {
            foreach ($node->children as $index => $child) {
                self::measure($child, "{$at}.children[{$index}]", $depth + 1, $nodes);
            }
        }
    }

    private static function node(mixed $node, string $at, int $depth, int &$nodes): RuleNode
    {
        self::count($at, $depth, $nodes);

        if (! is_array($node) || array_is_list($node)) {
            throw InvalidRuleDefinition::at($at, 'a node is an object with a "type".');
        }

        return match ($node['type'] ?? null) {
            'group' => self::group($node, $at, $depth, $nodes),
            'condition' => self::condition($node, $at),
            default => throw InvalidRuleDefinition::at("{$at}.type", 'a node\'s type is "group" or "condition".'),
        };
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private static function group(array $node, string $at, int $depth, int &$nodes): RuleGroup
    {
        self::fields($node, self::GROUP_FIELDS, $at);

        $match = is_string($node['match']) ? RuleCombinator::tryFrom($node['match']) : null;

        if ($match === null) {
            throw InvalidRuleDefinition::at("{$at}.match", 'a group matches "all" or "any".');
        }

        if (! is_array($node['children']) || ! array_is_list($node['children'])) {
            throw InvalidRuleDefinition::at("{$at}.children", 'a group\'s children are a list.');
        }

        $children = [];

        foreach ($node['children'] as $index => $child) {
            $children[] = self::node($child, "{$at}.children[{$index}]", $depth + 1, $nodes);
        }

        return RuleGroup::of($match, $children, $at);
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private static function condition(array $node, string $at): MetricCondition
    {
        self::fields($node, self::CONDITION_FIELDS, $at);

        return MetricCondition::from($node['metric'], $node['parameters'], $node['operator'], $node['operands'], $at);
    }

    /**
     * Exactly these fields: none missing, none extra.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $fields
     */
    private static function fields(array $node, array $fields, string $at): void
    {
        $given = array_map('strval', array_keys($node));

        $unknown = array_values(array_diff($given, $fields));

        if ($unknown !== []) {
            throw InvalidRuleDefinition::at($at, sprintf('unknown field "%s"; a %s has only %s.', $unknown[0], $node['type'], implode(', ', $fields)));
        }

        $missing = array_values(array_diff($fields, $given));

        if ($missing !== []) {
            throw InvalidRuleDefinition::at($at, sprintf('missing field "%s"; a %s has %s.', $missing[0], $node['type'], implode(', ', $fields)));
        }
    }

    private static function count(string $at, int $depth, int &$nodes): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw InvalidRuleDefinition::at($at, 'the tree is deeper than '.self::MAX_DEPTH.' levels.');
        }

        if (++$nodes > self::MAX_NODES) {
            throw InvalidRuleDefinition::at($at, 'the tree has more than '.self::MAX_NODES.' nodes.');
        }
    }
}
