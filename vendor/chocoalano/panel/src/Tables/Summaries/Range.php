<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Summaries;

use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The lowest and highest value, as one figure.
 *
 * Two aggregates rather than one, so it does not fit the single-aggregate
 * shape the others use and computes both itself.
 */
final class Range extends Summarizer
{
    public function aggregate(): ?string
    {
        return null;
    }

    public function summarize(QueryBuilder $query, string $column): mixed
    {
        $min = $query->clone()->min($column);
        $max = $query->clone()->max($column);

        return $min === null && $max === null ? null : ['min' => $min, 'max' => $max];
    }

    /**
     * @param  list<mixed>  $values
     * @return array{min: mixed, max: mixed}|null
     */
    protected function reduce(array $values): ?array
    {
        return $values === [] ? null : ['min' => min($values), 'max' => max($values)];
    }

    public function format(mixed $value): string
    {
        if (! is_array($value)) {
            return parent::format($value);
        }

        $min = $value['min'] ?? null;
        $max = $value['max'] ?? null;

        if ($min === null && $max === null) {
            return '—';
        }

        return $min === $max
            ? parent::format($min)
            : parent::format($min).' – '.parent::format($max);
    }
}
