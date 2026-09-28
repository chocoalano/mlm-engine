<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Summaries;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use PandaPanel\Support\Format;
use PandaPanel\Support\Label;

/**
 * One figure under a column.
 *
 * Computed by the database over the filtered query, not by adding up the
 * rows that happen to be on screen — a page total that changed when you
 * paged would be a different number wearing the same label. A summarizer that
 * wants the page says so, and then gets exactly the records shown.
 *
 * Formatting happens on the server like every other cell, so what crosses the
 * wire is finished text plus the raw figure for anything that wants it.
 */
abstract class Summarizer
{
    protected ?string $label = null;

    /** @var (Closure(mixed): string)|null */
    protected ?Closure $formatUsing = null;

    /**
     * Whether this figure describes the page rather than the whole result.
     *
     * Off by default, because "the total" almost always means the total, and
     * a number that silently meant "of these twenty rows" would be the more
     * surprising of the two.
     */
    protected bool $perPage = false;

    final public function __construct(protected readonly string $name) {}

    public static function make(string $name = ''): static
    {
        return new static($name);
    }

    /**
     * The SQL aggregate this maps to, or null for one that needs more than a
     * single aggregate and computes itself.
     */
    abstract public function aggregate(): ?string;

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @param  Closure(mixed): string  $callback
     */
    public function formatUsing(Closure $callback): static
    {
        $this->formatUsing = $callback;

        return $this;
    }

    public function perPage(bool $perPage = true): static
    {
        $this->perPage = $perPage;

        return $this;
    }

    public function isPerPage(): bool
    {
        return $this->perPage;
    }

    public function getName(): string
    {
        return $this->name === '' ? Str::lower(class_basename(static::class)) : $this->name;
    }

    public function getLabel(): string
    {
        return $this->label ?? Label::resolve(
            'fields',
            $this->getName(),
            fn (): string => Str::headline($this->getName()),
        );
    }

    /**
     * Computes the figure over the whole filtered result.
     *
     * Takes a query builder rather than an Eloquent one because the caller
     * has already decided *what* is being aggregated: a plain column reads
     * from the table, and a generated alias reads from a subquery that
     * produced it. Both arrive here as the same shape.
     */
    public function summarize(QueryBuilder $query, string $column): mixed
    {
        $aggregate = $this->aggregate();

        return $aggregate === null ? null : $query->clone()->{$aggregate}($column);
    }

    /**
     * Computes the figure over the records on screen.
     *
     * @param  list<Model>  $records
     */
    public function summarizeRecords(array $records, string $column): mixed
    {
        $values = array_map(
            static fn (Model $record): mixed => data_get($record, $column),
            $records,
        );

        return $this->reduce(array_values(array_filter(
            $values,
            static fn (mixed $value): bool => $value !== null,
        )));
    }

    /**
     * The PHP equivalent, for the per-page case.
     *
     * @param  list<mixed>  $values
     */
    abstract protected function reduce(array $values): mixed;

    public function format(mixed $value): string
    {
        if ($this->formatUsing !== null) {
            return ($this->formatUsing)($value);
        }

        return match (true) {
            $value === null => '—',
            is_float($value) => Format::trimmedNumber($value),
            is_int($value) => Format::number($value),
            is_scalar($value) => (string) $value,
            default => '—',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $value): array
    {
        return [
            'name' => $this->getName(),
            'label' => $this->getLabel(),
            'value' => $this->format($value),
            'raw' => is_scalar($value) ? $value : null,
            'perPage' => $this->perPage,
        ];
    }
}
