<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Production;

use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;
use PandaBear\Mlm\Planning\Rules\RuleCombinator;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Planning\Rules\RuleOperator;
use PHPUnit\Framework\TestCase;

/**
 * The rule language is closed (ADR-014): exactly these operators and these
 * two ways of combining conditions. A new operator is a language change,
 * made deliberately here — never a value a stored rule can introduce.
 */
final class RuleLanguageTest extends TestCase
{
    public function test_the_vocabulary_is_exactly_the_documented_one(): void
    {
        $this->assertSame(['!=', '>', '>=', '<', '<=', 'in', 'not_in', 'between'], array_map(static fn (RuleOperator $operator): string => $operator->value, RuleOperator::cases()));
        $this->assertSame(['all', 'any'], array_map(static fn (RuleCombinator $combinator): string => $combinator->value, RuleCombinator::cases()));
    }

    public function test_anything_outside_the_vocabulary_is_refused(): void
    {
        foreach (['=', '==', 'LIKE', 'expr', 'eval', 'regex', 'sql', '>= 1 OR 1=1'] as $operator) {
            $this->assertRefused(['type' => 'group', 'match' => 'all', 'children' => [['type' => 'condition', 'metric' => 'member.volume', 'parameters' => ['type' => 'sales'], 'operator' => $operator, 'operands' => ['1']]]]);
        }

        foreach (['none', 'xor', 'not'] as $match) {
            $this->assertRefused(['type' => 'group', 'match' => $match, 'children' => [['type' => 'condition', 'metric' => 'member.volume', 'parameters' => [], 'operator' => '>=', 'operands' => ['1']]]]);
        }

        $this->assertRefused(['type' => 'expression', 'code' => 'return true;']);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function assertRefused(array $definition): void
    {
        try {
            RuleDefinition::fromArray($definition);
        } catch (InvalidRuleDefinition) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The rule language accepted '.json_encode($definition).'.');
    }
}
