<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters\Constraints;

use PandaPanel\Tables\Enums\ConstraintOperator;

final class DateConstraint extends Constraint
{
    public function inputType(): string
    {
        return 'date';
    }

    /**
     * @return list<ConstraintOperator>
     */
    public function operators(): array
    {
        return [
            ConstraintOperator::EqualTo,
            ConstraintOperator::GreaterThan,
            ConstraintOperator::GreaterThanOrEqual,
            ConstraintOperator::LessThan,
            ConstraintOperator::LessThanOrEqual,
            ConstraintOperator::IsFilled,
            ConstraintOperator::IsBlank,
        ];
    }

    /**
     * Only a date the database will read as one. Anything else would be
     * compared as a string, which sorts nothing like a date.
     */
    public function accepts(ConstraintOperator $operator, mixed $value): bool
    {
        if (! $operator->needsValue()) {
            return true;
        }

        return is_string($value) && strtotime($value) !== false;
    }
}
