<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning\Rules;

/**
 * How a condition compares a metric's value with its operands. The complete
 * set: no other comparison, pattern or expression exists in the language.
 * Equality is deliberately absent until a plan needs it.
 *
 * `between` includes both bounds: the value is at least the first operand
 * and at most the second.
 */
enum RuleOperator: string
{
    case NotEqual = '!=';
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';
    case LessThan = '<';
    case LessThanOrEqual = '<=';
    case In = 'in';
    case NotIn = 'not_in';
    case Between = 'between';

    /**
     * How many operands the operator takes: at least the first, at most the
     * second — null for no upper bound beyond the language's own limit.
     *
     * @return array{int, int|null}
     */
    public function operands(): array
    {
        return match ($this) {
            self::In, self::NotIn => [1, null],
            self::Between => [2, 2],
            default => [1, 1],
        };
    }
}
