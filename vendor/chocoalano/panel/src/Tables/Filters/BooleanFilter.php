<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Enums\FilterType;

final class BooleanFilter extends Filter
{
    private string $trueLabel = 'Yes';

    private string $falseLabel = 'No';

    private bool $nullable = false;

    public function type(): FilterType
    {
        return FilterType::Boolean;
    }

    public function labels(string $true, string $false): self
    {
        $this->trueLabel = $true;
        $this->falseLabel = $false;

        return $this;
    }

    /**
     * Treats the column as "set" versus "not set" rather than true versus
     * false. This is what lets a nullable timestamp such as
     * `email_verified_at` act as a boolean filter without a second column.
     */
    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    public function sanitize(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 'true', $value === '1', $value === 1 => true,
            $value === false, $value === 'false', $value === '0', $value === 0 => false,
            default => null,
        };
    }

    protected function constrain(Builder $query, mixed $value): void
    {
        $column = $this->getColumn();

        if ($this->nullable) {
            $value === true
                ? $query->whereNotNull($column)
                : $query->whereNull($column);

            return;
        }

        $query->where($column, '=', $value);
    }

    /**
     * The chip says the label the control offered, not the boolean behind it.
     *
     * `sanitize()` returns a real `bool`, and the inherited `describe()` casts
     * a scalar — so the chip read `Verified: 1`, which is the exact thing
     * building indicators on the server exists to prevent. The labels are
     * already declared for the dropdown; this is the same pair.
     */
    protected function describe(mixed $value): string
    {
        return $value === true ? $this->trueLabel : $this->falseLabel;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'options' => [
                ['value' => '1', 'label' => $this->trueLabel],
                ['value' => '0', 'label' => $this->falseLabel],
            ],
        ];
    }
}
