<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Closure;
use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleCombinator;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Planning\Rules\RuleGroup;
use PandaBear\Mlm\Planning\Rules\RuleOperator;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * The safe rule language: groups of metric conditions, built, read back,
 * written out — exactly, and nothing else. Nothing here evaluates a rule.
 */
final class RuleDefinitionTest extends TestCase
{
    public function test_a_single_condition_in_an_all_group(): void
    {
        $rule = RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100'));

        $this->assertSame([
            'type' => 'group',
            'match' => 'all',
            'children' => [[
                'type' => 'condition',
                'metric' => 'member.volume',
                'parameters' => ['type' => 'sales'],
                'operator' => '>=',
                'operands' => ['100'],
            ]],
        ], $rule->toArray());
        $this->assertSame(RuleCombinator::All, $rule->root->match);
    }

    public function test_nested_groups_round_trip_exactly_through_json(): void
    {
        $rule = RuleDefinition::all(
            MetricCondition::of('member.volume', ['type' => 'sales'], RuleOperator::GreaterThanOrEqual, '100'),
            RuleGroup::any(
                MetricCondition::of('sponsor.network.volume', ['max_depth' => 3, 'type' => 'sales'], '>=', '500'),
                RuleGroup::all(
                    MetricCondition::of('placement.network.volume', ['type' => 'sales'], 'between', '400', '900.5'),
                    MetricCondition::of('member.volume', ['type' => 'retail'], 'not_in', '0', '-1'),
                ),
            ),
        );

        $read = RuleDefinition::fromJson($rule->toJson());

        $this->assertSame($rule->toArray(), $read->toArray());
        $this->assertSame($rule->toJson(), $read->toJson());
        $this->assertSame($rule->toArray(), RuleDefinition::fromArray($rule->toArray())->toArray());
    }

    public function test_parameters_are_written_in_canonical_key_order(): void
    {
        $one = RuleDefinition::all(MetricCondition::of('m.x', ['b' => 1, 'a' => ['z' => true, 'y' => null]], '>', '1'));
        $two = RuleDefinition::all(MetricCondition::of('m.x', ['a' => ['y' => null, 'z' => true], 'b' => 1], '>', '1'));

        $this->assertSame($one->toJson(), $two->toJson());
        $this->assertSame(['a' => ['y' => null, 'z' => true], 'b' => 1], $one->root->children[0]->parameters);
    }

    /**
     * @return array<string, array{string, list<string|int>, list<string>}>
     */
    public static function operators(): array
    {
        return [
            '!=' => ['!=', ['0'], ['0']],
            '>' => ['>', ['100'], ['100']],
            '>=' => ['>=', [100], ['100']],
            '<' => ['<', ['-25'], ['-25']],
            '<=' => ['<=', ['100.50'], ['100.5']],
            'in' => ['in', ['1', '2.5', '-3'], ['1', '2.5', '-3']],
            'not_in' => ['not_in', ['0.000001', '7'], ['0.000001', '7']],
            'between' => ['between', ['-25.5', '100'], ['-25.5', '100']],
            'between equal bounds' => ['between', ['5', '5.0'], ['5', '5']],
        ];
    }

    /**
     * @param  list<string|int>  $operands
     * @param  list<string>  $canonical
     */
    #[DataProvider('operators')]
    public function test_each_operator_takes_its_operands_as_exact_canonical_decimals(string $operator, array $operands, array $canonical): void
    {
        $condition = MetricCondition::of('member.volume', ['type' => 'sales'], $operator, ...$operands);

        $this->assertSame(RuleOperator::from($operator), $condition->operator);
        $this->assertSame($canonical, $condition->operands);
        $this->assertSame($canonical, RuleDefinition::fromJson(RuleDefinition::all($condition)->toJson())->root->children[0]->operands);
    }

    public function test_the_deepest_tree_within_the_limit_is_accepted(): void
    {
        $node = MetricCondition::of('member.volume', ['type' => 'sales'], '>', '0');

        // Root at depth 1, the condition at depth 16.
        foreach (range(1, RuleDefinition::MAX_DEPTH - 2) as $level) {
            $node = $level % 2 === 0 ? RuleGroup::all($node) : RuleGroup::any($node);
        }

        $rule = RuleDefinition::all($node);

        $this->assertSame($rule->toArray(), RuleDefinition::fromJson($rule->toJson())->toArray());
        $this->assertCount(1, $rule->conditions());
    }

    public function test_the_largest_tree_within_the_limit_is_accepted(): void
    {
        $conditions = array_fill(0, RuleDefinition::MAX_NODES - 1, MetricCondition::of('member.volume', ['type' => 'sales'], '>', '0'));

        $rule = RuleDefinition::all(...$conditions);

        $this->assertCount(RuleDefinition::MAX_NODES - 1, RuleDefinition::fromJson($rule->toJson())->conditions());
    }

    public function test_conditions_are_listed_in_tree_order_by_path(): void
    {
        $rule = RuleDefinition::any(
            MetricCondition::of('a.one', [], '>', '1'),
            RuleGroup::all(MetricCondition::of('b.two', [], '>', '2')),
        );

        $this->assertSame(['root.children[0]', 'root.children[1].children[0]'], array_keys($rule->conditions()));
        $this->assertSame(['a.one', 'b.two'], array_map(static fn (MetricCondition $condition): string => $condition->metric, array_values($rule->conditions())));
    }

    /**
     * @return array<string, array{Closure(): mixed, string}>
     */
    public static function refusedDefinitions(): array
    {
        $condition = ['type' => 'condition', 'metric' => 'member.volume', 'parameters' => ['type' => 'sales'], 'operator' => '>=', 'operands' => ['100']];
        $group = static fn (array ...$children): array => ['type' => 'group', 'match' => 'all', 'children' => $children];
        $read = static fn (mixed $definition): Closure => static fn (): RuleDefinition => RuleDefinition::fromArray($definition);
        $deep = $condition;

        foreach (range(1, RuleDefinition::MAX_DEPTH) as $level) {
            $deep = $group($deep);
        }

        return [
            'a naked root condition' => [$read($condition), 'the root is a group'],
            'an empty group' => [$read($group()), 'at least one child'],
            'an empty nested group' => [$read($group($group())), 'root.children[0].children: a group has at least one child'],
            'an unknown combinator' => [$read(['type' => 'group', 'match' => 'none', 'children' => [$condition]]), '"all" or "any"'],
            'an unknown node type' => [$read($group(['type' => 'formula'] + $condition)), 'type is "group" or "condition"'],
            'a node without a type' => [$read($group(array_diff_key($condition, ['type' => true]))), 'type is "group" or "condition"'],
            'a list for a node' => [$read([$condition]), 'a node is an object'],
            'an extra group field' => [$read(['note' => 'x'] + $group($condition)), 'unknown field "note"'],
            'an extra condition field' => [$read($group(['expression' => 'a + b'] + $condition)), 'unknown field "expression"'],
            'a missing metric' => [$read($group(array_diff_key($condition, ['metric' => true]))), 'missing field "metric"'],
            'a missing parameters field' => [$read($group(array_diff_key($condition, ['parameters' => true]))), 'missing field "parameters"'],
            'an empty metric key' => [$read($group(['metric' => ''] + $condition)), 'names the key of a registered metric'],
            'an unknown operator' => [$read($group(['operator' => '=='] + $condition)), 'the operator is one of'],
            'an operator that is not a string' => [$read($group(['operator' => 1] + $condition)), 'the operator is one of'],
            'children that are not a list' => [$read(['type' => 'group', 'match' => 'any', 'children' => ['a' => $condition]]), 'children are a list'],
            'operands that are not a list' => [$read($group(['operands' => ['x' => '1']] + $condition)), 'operands are a list'],
            'parameters that are a list' => [$read($group(['parameters' => ['sales']] + $condition)), 'parameters are a JSON object'],
            'a float operand read back' => [$read($group(['operands' => [100.5]] + $condition)), 'a float cannot hold most decimals exactly'],
            'too deep' => [$read($deep), 'deeper than 16 levels'],
            'too many nodes' => [$read($group(...array_fill(0, RuleDefinition::MAX_NODES, $condition))), 'more than 500 nodes'],
            'not an array' => [$read('all'), 'a node is an object'],
            'invalid JSON' => [static fn (): RuleDefinition => RuleDefinition::fromJson('{"type": "group",'), 'not valid JSON'],
            'JSON nested past the parser limit' => [static fn (): RuleDefinition => RuleDefinition::fromJson(str_repeat('[', 200).str_repeat(']', 200)), 'nests too deeply'],
            'a stored value that is not text' => [static fn (): RuleDefinition => RuleDefinition::fromJson(null), 'is JSON text'],
        ];
    }

    /**
     * @param  Closure(): mixed  $build
     */
    #[DataProvider('refusedDefinitions')]
    public function test_a_definition_outside_the_language_is_refused(Closure $build, string $reason): void
    {
        $this->expectException(InvalidRuleDefinition::class);
        $this->expectExceptionMessage($reason);

        $build();
    }

    /**
     * @return array<string, array{string, list<mixed>, string}>
     */
    public static function refusedOperands(): array
    {
        return [
            '!= with two' => ['!=', ['1', '2'], 'exactly 1 operand, 2 given'],
            '> with none' => ['>', [], 'exactly 1 operand, 0 given'],
            '>= with two' => ['>=', ['1', '2'], 'exactly 1 operand, 2 given'],
            '< with two' => ['<', ['1', '2'], 'exactly 1 operand, 2 given'],
            '<= with none' => ['<=', [], 'exactly 1 operand, 0 given'],
            'in with none' => ['in', [], '1 to 100 operands, 0 given'],
            'not_in with none' => ['not_in', [], '1 to 100 operands, 0 given'],
            'in with too many' => ['in', array_map('strval', range(1, 101)), '1 to 100 operands, 101 given'],
            'between with one' => ['between', ['1'], 'exactly 2 operands, 1 given'],
            'between with three' => ['between', ['1', '2', '3'], 'exactly 2 operands, 3 given'],
            'between upside down' => ['between', ['10', '9.999999'], 'lower bound first'],
            'a float' => ['>=', [100.0], 'a float cannot hold most decimals exactly'],
            'text' => ['>=', ['one hundred'], 'exact decimal'],
            'a number with a unit' => ['>=', ['100 USD'], 'exact decimal'],
            'scientific notation' => ['>=', ['1e3'], 'exact decimal'],
            'more than six decimal places' => ['>=', ['0.0000001'], 'at most 6 decimal places'],
            'a boolean' => ['>=', [true], 'exact decimal'],
            'null' => ['>=', [null], 'exact decimal'],
            'an array' => ['>=', [['100']], 'exact decimal'],
        ];
    }

    /**
     * @param  list<mixed>  $operands
     */
    #[DataProvider('refusedOperands')]
    public function test_operands_are_checked_for_their_operator(string $operator, array $operands, string $reason): void
    {
        $this->expectException(InvalidRuleDefinition::class);
        $this->expectExceptionMessage($reason);

        MetricCondition::of('member.volume', ['type' => 'sales'], $operator, ...$operands);
    }

    public function test_an_empty_group_cannot_be_built(): void
    {
        $this->expectException(InvalidRuleDefinition::class);
        $this->expectExceptionMessage('at least one child');

        RuleDefinition::any();
    }

    public function test_a_built_tree_is_held_to_the_same_limits(): void
    {
        $node = MetricCondition::of('member.volume', ['type' => 'sales'], '>', '0');

        foreach (range(1, RuleDefinition::MAX_DEPTH - 1) as $level) {
            $node = RuleGroup::all($node);
        }

        $this->expectException(InvalidRuleDefinition::class);
        $this->expectExceptionMessage('deeper than 16 levels');

        RuleDefinition::all($node);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unsafeParameters(): array
    {
        return [
            'an object' => [['type' => new stdClass]],
            'a closure' => [['type' => static fn (): string => 'sales']],
            'a resource' => [['type' => fopen('php://memory', 'r')]],
            'an enum' => [['operator' => RuleOperator::In]],
            'infinity' => [['limit' => INF]],
            'not a number' => [['limit' => NAN]],
            'invalid UTF-8' => [['type' => "\xB1\x31"]],
        ];
    }

    #[DataProvider('unsafeParameters')]
    public function test_parameters_are_json_data_and_nothing_else(mixed $parameters): void
    {
        $this->expectException(InvalidRuleDefinition::class);
        $this->expectExceptionMessage('root.children[0].parameters');

        RuleDefinition::fromArray(['type' => 'group', 'match' => 'all', 'children' => [
            ['type' => 'condition', 'metric' => 'member.volume', 'parameters' => $parameters, 'operator' => '>', 'operands' => ['1']],
        ]]);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function codeLikeStrings(): array
    {
        return [
            'a class name as the metric' => ['App\\Metrics\\Payout', '>=', '1', 'metric'],
            'SQL as the metric' => ['SELECT * FROM users', '>=', '1', 'metric'],
            'a function call as the metric' => ['phpinfo()', '>=', '1', 'metric'],
            'interpolation as the metric' => ['${env}', '>=', '1', 'metric'],
            'SQL as the operator' => ['member.volume', '>= 0 OR 1=1', '1', 'operator'],
            'a function call as the operator' => ['member.volume', 'system', '1', 'operator'],
            'a function call as an operand' => ['member.volume', '>=', 'phpinfo()', 'operands'],
            'SQL as an operand' => ['member.volume', '>=', '1; DROP TABLE mlm_members', 'operands'],
            'interpolation as an operand' => ['member.volume', '>=', '${1+1}', 'operands'],
        ];
    }

    /**
     * Refused by the shape the language allows, not by spotting suspicious
     * words: a key, an operator or an operand has one form, and this is not it.
     */
    #[DataProvider('codeLikeStrings')]
    public function test_code_like_text_never_passes_as_a_key_operator_or_operand(string $metric, string $operator, string $operand, string $field): void
    {
        $this->expectException(InvalidRuleDefinition::class);
        $this->expectExceptionMessage("condition.{$field}");

        MetricCondition::of($metric, ['type' => 'sales'], $operator, $operand);
    }

    public function test_parameter_text_is_kept_as_plain_data(): void
    {
        $text = 'SELECT * FROM users; phpinfo(); ${x} App\\Payout';
        $rule = RuleDefinition::all(MetricCondition::of('acme.custom', ['note' => $text], '>', '1'));

        $this->assertSame($text, RuleDefinition::fromJson($rule->toJson())->root->children[0]->parameters['note']);
    }
}
