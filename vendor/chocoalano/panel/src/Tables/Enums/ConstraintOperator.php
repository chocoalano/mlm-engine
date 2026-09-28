<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Enums;

/**
 * The comparisons a query-builder constraint may make.
 *
 * A closed set, and the only place an operator ever comes from. The whole
 * safety of a user-composed query rests on this: the request names an
 * operator, and a name that is not one of these does not exist. Nothing here
 * is ever concatenated from input — each case maps to a builder call.
 */
enum ConstraintOperator: string
{
    case Contains = 'contains';
    case DoesNotContain = 'does_not_contain';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case EqualTo = 'equal_to';
    case NotEqualTo = 'not_equal_to';
    case GreaterThan = 'greater_than';
    case GreaterThanOrEqual = 'greater_than_or_equal';
    case LessThan = 'less_than';
    case LessThanOrEqual = 'less_than_or_equal';
    case IsFilled = 'is_filled';
    case IsBlank = 'is_blank';
    case IsTrue = 'is_true';
    case IsFalse = 'is_false';

    public function label(): string
    {
        return match ($this) {
            self::Contains => 'contains',
            self::DoesNotContain => 'does not contain',
            self::StartsWith => 'starts with',
            self::EndsWith => 'ends with',
            self::EqualTo => 'is',
            self::NotEqualTo => 'is not',
            self::GreaterThan => 'is after',
            self::GreaterThanOrEqual => 'is at least',
            self::LessThan => 'is before',
            self::LessThanOrEqual => 'is at most',
            self::IsFilled => 'is filled',
            self::IsBlank => 'is blank',
            self::IsTrue => 'is true',
            self::IsFalse => 'is false',
        };
    }

    /**
     * Whether the comparison needs something to compare against.
     *
     * `is blank` does not, and demanding a value for it would make the
     * constraint impossible to express.
     */
    public function needsValue(): bool
    {
        return ! in_array($this, [self::IsFilled, self::IsBlank, self::IsTrue, self::IsFalse], true);
    }
}
