<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters\Constraints;

use PandaPanel\Tables\Enums\ConstraintOperator;

final class NumberConstraint extends Constraint
{
    public function inputType(): string
    {
        return 'number';
    }

    /**
     * @return list<ConstraintOperator>
     */
    public function operators(): array
    {
        return [
            ConstraintOperator::EqualTo,
            ConstraintOperator::NotEqualTo,
            ConstraintOperator::GreaterThan,
            ConstraintOperator::GreaterThanOrEqual,
            ConstraintOperator::LessThan,
            ConstraintOperator::LessThanOrEqual,
            ConstraintOperator::IsFilled,
            ConstraintOperator::IsBlank,
        ];
    }

    /**
     * A comparison against a non-number is not a narrower query, it is a
     * meaningless one — so it is refused rather than coerced to zero.
     */
    public function accepts(ConstraintOperator $operator, mixed $value): bool
    {
        return $operator->needsValue() === false || is_numeric($value);
    }
}
