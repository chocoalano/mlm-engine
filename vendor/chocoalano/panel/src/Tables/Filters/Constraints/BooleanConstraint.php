<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters\Constraints;

use PandaPanel\Tables\Enums\ConstraintOperator;

final class BooleanConstraint extends Constraint
{
    public function inputType(): string
    {
        return 'none';
    }

    /**
     * Both operators carry their own answer, so a boolean constraint needs no
     * value input at all.
     *
     * @return list<ConstraintOperator>
     */
    public function operators(): array
    {
        return [
            ConstraintOperator::IsTrue,
            ConstraintOperator::IsFalse,
            ConstraintOperator::IsFilled,
            ConstraintOperator::IsBlank,
        ];
    }
}
