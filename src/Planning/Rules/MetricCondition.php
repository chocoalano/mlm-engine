<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning\Rules;

use PandaBear\Mlm\Exceptions\InvalidMetric;
use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;
use PandaBear\Mlm\Metrics\MetricValue;
use PandaBear\Mlm\Planning\DefinitionInput;
use PandaBear\Mlm\Volume\Quantity;

/**
 * A comparison of one metric's value — named by its registered key, with
 * that metric's parameters — against exact operands:
 *
 *   MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100')
 *
 * Data only. The metric key is not checked against the registry here, and
 * nothing is resolved: plan validation checks the reference, and a later
 * phase evaluates it.
 */
final readonly class MetricCondition implements RuleNode
{
    /**
     * The most operands one condition may list — for `in` and `not_in`: a
     * guard against a pathological definition, not a business limit.
     */
    public const MAX_OPERANDS = 100;

    /**
     * @param  array<string, mixed>  $parameters  canonical
     * @param  list<string>  $operands  canonical decimal strings
     */
    private function __construct(
        public string $metric,
        public array $parameters,
        public RuleOperator $operator,
        public array $operands,
    ) {}

    /**
     * Operands are exact decimals: strings such as "100" or "-25.5", or
     * integers. A float is refused — it cannot hold most decimals exactly.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidRuleDefinition
     */
    public static function of(string $metric, array $parameters, RuleOperator|string $operator, mixed ...$operands): self
    {
        return self::from($metric, $parameters, $operator, array_values($operands), 'condition');
    }

    /**
     * @internal the checks every condition passes, however it is built or read
     */
    public static function from(mixed $metric, mixed $parameters, mixed $operator, mixed $operands, string $at): self
    {
        if (! is_string($metric) || $metric === '') {
            throw InvalidRuleDefinition::at("{$at}.metric", 'a condition names the key of a registered metric.');
        }

        if (! DefinitionInput::isIdentifier($metric, DefinitionInput::DRIVER_LENGTH)) {
            throw InvalidRuleDefinition::at("{$at}.metric", sprintf(
                'a metric key is 1–%d lowercase letters, digits, ".", "-" or "_", starting with a letter or digit.',
                DefinitionInput::DRIVER_LENGTH,
            ));
        }

        $problem = DefinitionInput::parametersProblem($parameters);

        if ($problem !== null) {
            throw InvalidRuleDefinition::at("{$at}.parameters", $problem);
        }

        $operator = $operator instanceof RuleOperator ? $operator : (is_string($operator) ? RuleOperator::tryFrom($operator) : null)
            ?? throw InvalidRuleDefinition::at("{$at}.operator", sprintf(
                'the operator is one of %s.',
                implode(', ', array_map(static fn (RuleOperator $known): string => $known->value, RuleOperator::cases())),
            ));

        return new self($metric, DefinitionInput::canonical($parameters), $operator, self::operands($operator, $operands, "{$at}.operands"));
    }

    public function toArray(): array
    {
        return [
            'type' => 'condition',
            'metric' => $this->metric,
            'parameters' => $this->parameters,
            'operator' => $this->operator->value,
            'operands' => $this->operands,
        ];
    }

    /**
     * @return list<string>
     */
    private static function operands(RuleOperator $operator, mixed $operands, string $at): array
    {
        if (! is_array($operands) || ! array_is_list($operands)) {
            throw InvalidRuleDefinition::at($at, 'operands are a list.');
        }

        [$least, $most] = $operator->operands();
        $most ??= self::MAX_OPERANDS;
        $count = count($operands);

        if ($count < $least || $count > $most) {
            throw InvalidRuleDefinition::at($at, sprintf(
                '"%s" takes %s, %d given.',
                $operator->value,
                match (true) {
                    $least === $most => $least === 1 ? 'exactly 1 operand' : "exactly {$least} operands",
                    default => "{$least} to {$most} operands",
                },
                $count,
            ));
        }

        $canonical = [];

        foreach ($operands as $index => $operand) {
            if (is_float($operand)) {
                throw InvalidRuleDefinition::at("{$at}[{$index}]", 'a float cannot hold most decimals exactly — pass a string such as "100.5".');
            }

            try {
                $canonical[] = MetricValue::of($operand)->value();
            } catch (InvalidMetric $exception) {
                throw InvalidRuleDefinition::at("{$at}[{$index}]", 'an operand is an exact decimal such as "100" or "-25.5", with at most '.Quantity::SCALE.' decimal places.');
            }
        }

        if ($operator === RuleOperator::Between && Quantity::of($canonical[0])->compare(Quantity::of($canonical[1])) > 0) {
            throw InvalidRuleDefinition::at($at, 'between takes its lower bound first; both bounds are included.');
        }

        return $canonical;
    }
}
