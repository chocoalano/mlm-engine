<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Enums\RelationshipAggregate;
use PandaPanel\Tables\Enums\SortDirection;

/**
 * Reading a relation rather than a column.
 *
 * Three separate things, deliberately kept apart because they need different
 * work from the query:
 *
 * - **Aggregates** (`counts`, `exists`, `sum`, …) are computed in the select,
 *   so the column has to shape the query before any row is read. That is what
 *   `Column::applyQuery()` exists for. Doing it per row instead would be a
 *   query per record — the thing `$with` exists to prevent.
 * - **Sorting** by a relation cannot be an `ORDER BY` on a column that is not
 *   in the table. A correlated subquery orders by the related value without
 *   joining, so the row count cannot change underneath the paginator — a join
 *   against a to-many relation would multiply rows and quietly break both the
 *   page size and the total.
 * - **Searching** a relation is `whereHas`, not a `LIKE` on a column that
 *   does not exist locally.
 *
 * An aggregate lands on the model as a generated attribute (`posts_count`),
 * which is a real column of the result set — so sorting one is an ordinary
 * `ORDER BY` and needs none of the above.
 */
trait HasRelationshipState
{
    private ?string $aggregateRelation = null;

    private ?RelationshipAggregate $aggregate = null;

    private ?string $aggregateColumn = null;

    /** The relation and column an ordinary sort should defer to. */
    private ?string $sortRelation = null;

    private ?string $sortRelationColumn = null;

    /**
     * How many related records there are.
     */
    public function counts(string $relation): static
    {
        return $this->aggregateBy($relation, RelationshipAggregate::Count, null);
    }

    /**
     * Whether there are any, as a boolean — cheaper than counting when the
     * number is not what is being shown.
     */
    public function exists(string $relation): static
    {
        return $this->aggregateBy($relation, RelationshipAggregate::Exists, null);
    }

    public function sum(string $relation, string $column): static
    {
        return $this->aggregateBy($relation, RelationshipAggregate::Sum, $column);
    }

    public function avg(string $relation, string $column): static
    {
        return $this->aggregateBy($relation, RelationshipAggregate::Avg, $column);
    }

    public function min(string $relation, string $column): static
    {
        return $this->aggregateBy($relation, RelationshipAggregate::Min, $column);
    }

    public function max(string $relation, string $column): static
    {
        return $this->aggregateBy($relation, RelationshipAggregate::Max, $column);
    }

    /**
     * Sorts by a column on a related record.
     *
     * Separate from `sortable()` because the strategy is different: this
     * orders by a correlated subquery rather than by a column of this table.
     */
    public function sortableByRelation(string $relation, string $column): static
    {
        $this->sortable = true;
        $this->sortRelation = $relation;
        $this->sortRelationColumn = $column;

        return $this;
    }

    public function getAggregateRelation(): ?string
    {
        return $this->aggregateRelation;
    }

    public function getSortRelation(): ?string
    {
        return $this->sortRelation;
    }

    /**
     * The attribute an aggregate lands on, which is the name Eloquent
     * generates and the name the cell then reads.
     */
    public function aggregateAttribute(): ?string
    {
        if ($this->aggregateRelation === null || $this->aggregate === null) {
            return null;
        }

        return $this->aggregate->attributeFor($this->aggregateRelation, $this->aggregateColumn);
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function applyQuery(Builder $query): void
    {
        if ($this->aggregateRelation === null || $this->aggregate === null) {
            return;
        }

        $this->aggregate->apply($query, $this->aggregateRelation, $this->aggregateColumn);
    }

    /**
     * Orders by the related value without joining.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function applyRelationshipSort(Builder $query, SortDirection $direction): void
    {
        if ($this->sortRelation === null || $this->sortRelationColumn === null) {
            return;
        }

        $relation = $query->getModel()->{$this->sortRelation}();

        $query->orderBy(
            $relation->getRelated()->newQuery()
                ->select($this->sortRelationColumn)
                ->whereColumn(
                    $relation->getQualifiedForeignKeyName(),
                    $relation->getQualifiedParentKeyName(),
                )
                ->limit(1)
                ->getQuery(),
            $direction->value,
        );
    }

    /**
     * Whether this column's summary aggregates a generated alias rather than
     * a real column.
     *
     * The difference decides how a summary has to be written. `passkeys_count`
     * exists only in the SELECT list, so `sum(passkeys_count)` is an unknown
     * column — the sum has to happen outside a subquery that produced it.
     */
    public function summaryUsesAggregate(): bool
    {
        return $this->aggregateAttribute() !== null;
    }

    /**
     * The attribute a cell reads: the aggregate's generated name when there
     * is one, the column's own name otherwise.
     */
    protected function stateAttribute(): string
    {
        return $this->aggregateAttribute() ?? $this->name;
    }

    private function aggregateBy(string $relation, RelationshipAggregate $aggregate, ?string $column): static
    {
        $this->aggregateRelation = $relation;
        $this->aggregate = $aggregate;
        $this->aggregateColumn = $column;

        // The generated attribute is a real column of the result set, so
        // sorting it needs no special strategy — but the column still has to
        // be told it may be sorted.
        $this->sortColumn ??= $this->aggregateAttribute();

        return $this;
    }
}
