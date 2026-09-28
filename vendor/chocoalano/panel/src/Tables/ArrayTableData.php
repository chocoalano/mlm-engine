<?php

declare(strict_types=1);

namespace PandaPanel\Tables;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use PandaPanel\Tables\Columns\Column;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\Enums\TableLayout;

/**
 * A table over records that are not in the database.
 *
 * An API response, a config file, a computed report. The whole rest of the
 * table builder is unchanged: the same columns, the same search and sort
 * declarations, the same serialization. Only *where the rows come from* and
 * *who does the work* differ — here it is PHP over an array rather than SQL
 * over a builder.
 *
 * Rows must still be `Model` instances, because a column reads its value with
 * `data_get()` and every renderer downstream expects the same shape. A
 * non-persisted model is the cheapest way to get that: it needs no table, and
 * `Model::make()` on a `$guarded = []` class is the whole ceremony.
 *
 * The honest limitation is scale. Search, sort, and pagination all happen in
 * memory over the full set, so this is for tens or hundreds of rows — the
 * size a config file or an API page actually is. Anything larger belongs in a
 * query, where the database can do the work.
 */
final readonly class ArrayTableData
{
    /**
     * @param  Collection<int, Model>  $records
     */
    public function __construct(
        private TableSchema $schema,
        private Collection $records,
        private Request $request,
        private ?string $namespace = null,
    ) {}

    /**
     * @param  iterable<int, Model>  $records
     */
    public static function make(
        TableSchema $schema,
        iterable $records,
        Request $request,
        ?string $namespace = null,
    ): self {
        return new self($schema, new Collection($records), $request, $namespace);
    }

    /**
     * @return LengthAwarePaginator<int, Model>
     */
    public function paginate(): LengthAwarePaginator
    {
        $records = $this->sort($this->search($this->records));

        $perPage = $this->schema->getDefaultPerPage();
        $page = max(1, (int) ($this->param('page') ?? 1));

        return new LengthAwarePaginator(
            items: $records->forPage($page, $perPage)->values(),
            total: $records->count(),
            perPage: $perPage,
            currentPage: $page,
        );
    }

    /**
     * The same whitelist rule the query layer applies: only columns that
     * declared themselves searchable are matched, and the term never becomes
     * anything but a substring test.
     *
     * @param  Collection<int, Model>  $records
     * @return Collection<int, Model>
     */
    private function search(Collection $records): Collection
    {
        $term = $this->param('search');
        $columns = $this->schema->getSearchColumns();

        if ($term === null || trim($term) === '' || $columns === []) {
            return $records;
        }

        $needle = mb_strtolower(trim($term));

        return $records->filter(static function (Model $record) use ($columns, $needle): bool {
            foreach ($columns as $column) {
                $value = data_get($record, $column);

                if (is_scalar($value) && str_contains(mb_strtolower((string) $value), $needle)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * @param  Collection<int, Model>  $records
     * @return Collection<int, Model>
     */
    private function sort(Collection $records): Collection
    {
        $requested = $this->param('sort');

        $column = $requested === null ? null : $this->schema->getColumn($requested);

        // Unknown or non-sortable is ignored, exactly as it is in the query
        // layer: the schema is the whitelist wherever the rows come from.
        if ($column === null || ! $column->isSortable()) {
            return $records;
        }

        $descending = SortDirection::fromRequest($this->param('direction')) === SortDirection::Descending;

        return $records
            ->sortBy(
                static fn (Model $record): mixed => data_get($record, $column->getSortColumn()),
                descending: $descending,
            )
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $requested = $this->param('sort');
        $column = $requested === null ? null : $this->schema->getColumn($requested);

        return [
            'search' => $this->param('search'),
            'sort' => $column?->isSortable() === true ? $column->getName() : null,
            'direction' => SortDirection::fromRequest($this->param('direction'))->value,
            'perPage' => $this->schema->getDefaultPerPage(),
            'filters' => [],
            'filterIndicators' => [],
            'columnSearches' => [],
            'columns' => [
                'visible' => $this->schema->defaultVisibleColumnNames(),
                'order' => $this->schema->columnNames(),
            ],
            // An array table is a widget's table: no card face, no toggle, no
            // session. The layout is a constant rather than a decision — but
            // the key is present, because the frontend type says it is.
            'layout' => TableLayout::Table->value,
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, Model>  $records
     * @return array<string, int>
     */
    public function pagination(LengthAwarePaginator $records): array
    {
        return [
            'page' => $records->currentPage(),
            'perPage' => $records->perPage(),
            'total' => $records->total(),
            'lastPage' => $records->lastPage(),
            'from' => (int) $records->firstItem(),
            'to' => (int) $records->lastItem(),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, Model>  $records
     * @return list<array<string, mixed>>
     */
    public function rows(LengthAwarePaginator $records): array
    {
        $schema = $this->schema;

        return array_values(array_map(
            static fn (Model $record): array => $schema->toRow($record),
            $records->items(),
        ));
    }

    /**
     * Reads state from the namespace when there is one, the same way
     * `TableQuery` does — a table over an array can still sit beside others
     * on one page.
     */
    private function param(string $key): ?string
    {
        $value = $this->namespace === null
            ? $this->request->query($key)
            : data_get($this->request->query(), $this->namespace.'.'.$key);

        return is_string($value) ? $value : null;
    }

    /**
     * The sortable columns, for a caller that wants to know what this data
     * source can honour.
     *
     * @return list<Column>
     */
    public function sortableColumns(): array
    {
        return $this->schema->getSortableColumns();
    }
}
