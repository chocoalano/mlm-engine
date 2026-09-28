<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Enums;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a column computes over a relation.
 *
 * A closed set because each case maps to a specific Eloquent call and to the
 * attribute name Eloquent generates for it. A free-form function name would
 * be a SQL function chosen by a schema and rendered into a select.
 */
enum RelationshipAggregate: string
{
    case Count = 'count';
    case Exists = 'exists';
    case Sum = 'sum';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';

    /**
     * The attribute Eloquent lands the result on.
     *
     * Derived with the same rule Eloquent uses, so the column reads exactly
     * what the query wrote rather than a name the two have to agree on
     * separately.
     */
    public function attributeFor(string $relation, ?string $column): string
    {
        $base = str_replace('.', '_', $relation);

        return match ($this) {
            self::Count => $base.'_count',
            self::Exists => $base.'_exists',
            default => $base.'_'.$this->value.'_'.str_replace('.', '_', (string) $column),
        };
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function apply(Builder $query, string $relation, ?string $column): void
    {
        match ($this) {
            self::Count => $query->withCount($relation),
            self::Exists => $query->withExists($relation),
            default => $column === null
                ? null
                : $query->withAggregate($relation, $column, $this->value),
        };
    }
}
