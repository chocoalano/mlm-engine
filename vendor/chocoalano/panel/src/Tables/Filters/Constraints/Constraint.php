<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters\Constraints;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Support\Label;
use PandaPanel\Tables\Enums\ConstraintOperator;

/**
 * One column a query builder is allowed to constrain.
 *
 * The declaration *is* the whitelist. A request names a column and an
 * operator; both are looked up here, and a name that was not declared does
 * not exist. No part of a submitted rule is ever concatenated into SQL — the
 * column comes from this object and the operator from a closed enum.
 */
abstract class Constraint
{
    protected ?string $label = null;

    protected ?string $column = null;

    final public function __construct(protected readonly string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    /**
     * The comparisons this kind of column supports.
     *
     * @return list<ConstraintOperator>
     */
    abstract public function operators(): array;

    /**
     * The control the frontend renders for the value.
     */
    abstract public function inputType(): string;

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * The database column, when it differs from the name the request uses.
     */
    public function column(string $column): static
    {
        $this->column = $column;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label ?? Label::resolve(
            'fields',
            $this->name,
            fn (): string => Str::headline($this->name),
        );
    }

    public function getColumn(): string
    {
        return $this->column ?? $this->name;
    }

    public function supports(ConstraintOperator $operator): bool
    {
        return in_array($operator, $this->operators(), true);
    }

    /**
     * Applies one rule.
     *
     * The operator has already been checked against `operators()`, so the
     * match below is total for anything that reaches it.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function apply(Builder $query, ConstraintOperator $operator, mixed $value): void
    {
        $column = $this->getColumn();

        match ($operator) {
            ConstraintOperator::Contains => $query->where($column, 'like', '%'.self::escape($value).'%'),
            ConstraintOperator::DoesNotContain => $query->whereNot($column, 'like', '%'.self::escape($value).'%'),
            ConstraintOperator::StartsWith => $query->where($column, 'like', self::escape($value).'%'),
            ConstraintOperator::EndsWith => $query->where($column, 'like', '%'.self::escape($value)),
            ConstraintOperator::EqualTo => $query->where($column, '=', $value),
            ConstraintOperator::NotEqualTo => $query->where($column, '!=', $value),
            ConstraintOperator::GreaterThan => $query->where($column, '>', $value),
            ConstraintOperator::GreaterThanOrEqual => $query->where($column, '>=', $value),
            ConstraintOperator::LessThan => $query->where($column, '<', $value),
            ConstraintOperator::LessThanOrEqual => $query->where($column, '<=', $value),
            ConstraintOperator::IsFilled => $query->whereNotNull($column),
            ConstraintOperator::IsBlank => $query->whereNull($column),
            ConstraintOperator::IsTrue => $query->where($column, '=', true),
            ConstraintOperator::IsFalse => $query->where($column, '=', false),
        };
    }

    /**
     * Whether a submitted value is acceptable for this constraint.
     */
    public function accepts(ConstraintOperator $operator, mixed $value): bool
    {
        return ! $operator->needsValue() || (is_scalar($value) && $value !== '');
    }

    /**
     * Escapes the LIKE wildcards, so a value containing `%` matches
     * literally instead of scanning the whole table.
     */
    protected static function escape(mixed $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) (is_scalar($value) ? $value : ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->getLabel(),
            'input' => $this->inputType(),
            'operators' => array_map(
                static fn (ConstraintOperator $operator): array => [
                    'value' => $operator->value,
                    'label' => $operator->label(),
                    'needsValue' => $operator->needsValue(),
                ],
                $this->operators(),
            ),
        ];
    }
}
