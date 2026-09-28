<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters\Constraints;

use PandaPanel\Tables\Enums\ConstraintOperator;

final class TextConstraint extends Constraint
{
    public function inputType(): string
    {
        return 'text';
    }

    /**
     * @return list<ConstraintOperator>
     */
    public function operators(): array
    {
        return [
            ConstraintOperator::Contains,
            ConstraintOperator::DoesNotContain,
            ConstraintOperator::StartsWith,
            ConstraintOperator::EndsWith,
            ConstraintOperator::EqualTo,
            ConstraintOperator::NotEqualTo,
            ConstraintOperator::IsFilled,
            ConstraintOperator::IsBlank,
        ];
    }
}
