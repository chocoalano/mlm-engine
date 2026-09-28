<?php

declare(strict_types=1);

namespace PandaPanel\Tables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use PandaPanel\Tables\Columns\Column;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\Enums\TableLayout;

/**
 * Applies the URL state to a resource query.
 *
 * The URL is the source of truth for page, perPage, search, sort, direction,
 * and filters, so back, forward, refresh, and bookmark all behave. Every one
 * of those values is validated against the schema before it touches the
 * builder: an unknown sort column, an out-of-range perPage, or an
 * unrecognised filter is ignored rather than passed through.
 *
 * This layer never starts a query. It receives the resource's own query so
 * tenant, module, and permission scopes stay applied.
 *
 * A namespace moves the whole state under one query-string key
 * (`?relations[posts][sort]=title`). A record page can carry several relation
 * tables at once, and each has to be able to sort and paginate without
 * touching the others — a shared `?page=` would move all of them together.
 */
final readonly class TableQuery
{
    /**
     * @param  string|null  $sessionKey  where remembered state is stored, or null for a table that remembers nothing
     */
    public function __construct(
        private TableSchema $schema,
        private Request $request,
        private ?string $namespace = null,
        private ?string $sessionKey = null,
    ) {}

    /**
     * The query-string key this table's state lives under, for a frontend
     * that has to write it back.
     */
    public function namespace(): ?string
    {
        return $this->namespace;
    }

    /**
     * One state value, from the namespace when there is one.
     *
     * Only scalars are read back: a nested array where a sort column belongs
     * is not a value this layer has any use for, and refusing it here means
     * every reader below can assume a string.
     */
    private function param(string $key): ?string
    {
        $value = $this->namespaced($key);

        return is_string($value) ? $value : null;
    }

    /**
     * A state value that survives leaving the page.
     *
     * The request wins whenever it says anything at all, *including* saying
     * the value is now empty — otherwise clearing a search would be undone
     * by the thing that remembered it. Absence is the only case that falls
     * back to what was stored.
     *
     * Nothing read back here skips validation: a remembered sort column goes
     * through the same schema whitelist a fresh one does, so a stale session
     * cannot name a column the table no longer has.
     */
    private function persistedParam(string $key, bool $enabled): ?string
    {
        $raw = $this->namespaced($key);

        // A table can be rendered without session middleware — a console
        // command, an endpoint outside the web stack — and remembering
        // nothing is the right answer there, not a fatal.
        if (! $enabled || $this->sessionKey === null || ! $this->request->hasSession()) {
            return is_string($raw) ? $raw : null;
        }

        $store = $this->sessionKey.'.'.$key;

        if ($raw !== null) {
            $value = is_string($raw) ? $raw : null;

            $this->request->session()->put($store, $value);

            return $value;
        }

        $remembered = $this->request->session()->get($store);

        return is_string($remembered) ? $remembered : null;
    }

    /**
     * One value out of this table's slice of the query string.
     *
     * `Request::query('a.b')` does not walk a dotted path — the query bag
     * looks the whole string up as one key — so the namespace is resolved
     * against the array with `data_get` instead.
     */
    private function namespaced(string $key): mixed
    {
        if ($this->namespace === null) {
            return $this->request->query($key);
        }

        return data_get($this->request->query(), $this->namespace.'.'.$key);
    }

    /**
     * Whether the request carried this parameter at all — regardless of what
     * it carried in it.
     *
     * The distinction the value alone cannot make. A query string has no way
     * to spell an empty array, so "I have cleared every filter" is sent as
     * `filters=` — and `ConvertEmptyStringsToNull`, which nearly every Laravel
     * application runs, rewrites that to null before any of this sees it.
     * Reading the value then gives the same answer for "the user cleared
     * everything" and "the user said nothing", and with persistence on those
     * two mean opposite things: one must wipe the stored filters, the other
     * must restore them. Clearing appeared to do nothing at all.
     *
     * The middleware replaces values and leaves keys alone, so the key is
     * still there to be asked about. That is what makes an explicit empty
     * distinguishable from silence without inventing a sentinel value a real
     * filter could one day collide with.
     */
    private function namespacedPresent(string $key): bool
    {
        return Arr::has(
            $this->request->query(),
            $this->namespace === null ? $key : $this->namespace.'.'.$key,
        );
    }

    /**
     * Everything the table state says about *which* records and in what
     * order, and nothing about how many.
     *
     * Split out because an export wants exactly this: the rows the list was
     * showing, all of them. Sharing the method rather than repeating the
     * calls is what keeps a file from containing a different set of records
     * from the screen it was started from.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrain(Builder $query): void
    {
        $this->schema->applyColumnQueries($query);
        $this->applyBaseQueryFilters($query);
        $this->applySearch($query);
        $this->applyFilters($query);
        $this->applySort($query);
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return LengthAwarePaginator<int, covariant Model>
     */
    public function paginate(Builder $query): LengthAwarePaginator
    {
        $this->constrain($query);

        return $query->paginate(
            perPage: $this->perPage(),
            page: $this->page(),
        )->withQueryString();
    }

    /**
     * Paginates through a relation rather than through its query.
     *
     * The two are not interchangeable for a many-to-many: `getQuery()` hands
     * back the underlying builder, which knows nothing about the pivot, so
     * paginating it produces rows with no `pivot` relation and a pivot column
     * reads as null. `BelongsToMany::paginate()` selects the aliased pivot
     * columns and hydrates them afterwards.
     *
     * The state still goes on to the same builder instance the relation
     * holds, so search, filters, and sort apply exactly as they do above.
     *
     * @param  Relation<Model, Model, mixed>  $relation
     * @return LengthAwarePaginator<int, covariant Model>
     */
    public function paginateRelation(Relation $relation): LengthAwarePaginator
    {
        $this->constrain($relation->getQuery());

        /** @var LengthAwarePaginator<int, covariant Model> $paginator */
        $paginator = $relation->paginate(
            perPage: $this->perPage(),
            page: $this->page(),
        );

        return $paginator->withQueryString();
    }

    /**
     * The resolved state, echoed back so the frontend renders controls from
     * what the server actually applied rather than from what was requested.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $sort = $this->sortColumn();

        return [
            'search' => $this->search(),
            'sort' => $sort?->getName(),
            'direction' => $this->direction()->value,
            'perPage' => $this->perPage(),
            'filters' => $this->activeFilters(),
            'filterIndicators' => $this->filterIndicators(),
            'columnSearches' => $this->activeColumnSearches(),
            'columns' => $this->columnState(),
            'group' => $this->activeGroup()?->getName(),
            'layout' => $this->layout()->value,
        ];
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applySearch(Builder $query): void
    {
        $this->applyGlobalSearch($query);
        $this->applyColumnSearches($query);
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applyGlobalSearch(Builder $query): void
    {
        $term = $this->search();
        $columns = $this->schema->getSearchColumns();
        $relations = $this->schema->getSearchRelations();

        if ($term === null || ($columns === [] && $relations === [])) {
            return;
        }

        // Each word must match somewhere, rather than the whole string having
        // to match one column: "ada lovelace" across a first-name and a
        // last-name column finds nothing as one string and the obvious record
        // as two words.
        foreach ($this->searchTerms($term) as $word) {
            $query->where(function (Builder $group) use ($columns, $relations, $word): void {
                $this->matchAnywhere($group, $columns, $relations, $word);
            });
        }
    }

    /**
     * A column's own search box, ANDed with everything else.
     *
     * Narrowing rather than widening is the whole point of a per-column
     * search: it answers "of these rows, which have this in that column".
     *
     * @param  Builder<covariant Model>  $query
     */
    private function applyColumnSearches(Builder $query): void
    {
        foreach ($this->columnSearchParams() as $name => $value) {
            if (! is_string($name) || ! is_string($value) || trim($value) === '') {
                continue;
            }

            // Only a column that declared its own box, so a request naming
            // any other column searches nothing.
            $column = $this->schema->getIndividuallySearchableColumn($name);

            if ($column === null) {
                continue;
            }

            $columns = [];
            $relations = [];

            foreach ($column->getSearchColumns() as $path) {
                if (str_contains($path, '.')) {
                    $relations[] = $path;
                } else {
                    $columns[] = $path;
                }
            }

            $term = mb_substr(trim($value), 0, 255);

            foreach ($this->searchTerms($term) as $word) {
                $query->where(function (Builder $group) use ($columns, $relations, $word): void {
                    $this->matchAnywhere($group, $columns, $relations, $word);
                });
            }
        }
    }

    /**
     * One word, matched against any of the given columns or relations.
     *
     * Always inside a group, so an `orWhere` here can never widen a
     * constraint that was already applied.
     *
     * @param  Builder<covariant Model>  $group
     * @param  list<string>  $columns
     * @param  list<string>  $relations
     */
    private function matchAnywhere(Builder $group, array $columns, array $relations, string $word): void
    {
        $like = '%'.$this->escapeLike($word).'%';

        foreach ($columns as $column) {
            $group->orWhere($column, 'like', $like);
        }

        // A dotted name is a relation and its column, so it is matched with
        // `whereHas` — it is not a column of this table, and a `LIKE` on it
        // would be a SQL error rather than no result.
        foreach ($relations as $path) {
            $separator = strrpos($path, '.');

            if ($separator === false) {
                continue;
            }

            $relation = substr($path, 0, $separator);
            $column = substr($path, $separator + 1);

            $group->orWhereHas(
                $relation,
                static fn (Builder $related) => $related->where($column, 'like', $like),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function searchTerms(string $term): array
    {
        if (! $this->schema->shouldSplitSearchTerms()) {
            return [$term];
        }

        $words = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY);

        return $words === false || $words === [] ? [$term] : $words;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function columnSearchParams(): array
    {
        $searches = $this->namespaced('columnSearch');

        return is_array($searches) ? $searches : [];
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applyFilters(Builder $query): void
    {
        foreach ($this->resolvedFilters() as $name => $value) {
            $this->schema->getFilter($name)?->apply($query, $value);
        }
    }

    /**
     * The pass that lets a filter change what the query *is* rather than
     * narrow what it returns.
     *
     * Before the search, and outside the grouping the ordinary constraints
     * run in: a filter that joins a table or lifts a global scope has to have
     * done so by the time anything reads it, and an `orWhere` in the search's
     * group would widen the search rather than the filter.
     *
     * @param  Builder<covariant Model>  $query
     */
    private function applyBaseQueryFilters(Builder $query): void
    {
        foreach ($this->resolvedFilters() as $name => $value) {
            $this->schema->getFilter($name)?->applyBaseQuery($query, $value);
        }
    }

    /**
     * Every filter's effective value: what the request said, what the session
     * remembered, or what the table declared as its default.
     *
     * A default is a decision the table made rather than an absence, so it
     * applies when the request is silent — but the request saying a filter is
     * now empty still wins, or a default could never be cleared.
     *
     * @return array<string, mixed>
     */
    private function resolvedFilters(): array
    {
        ['filters' => $requested, 'specified' => $specified] = $this->readFilterParams();
        $resolved = [];

        foreach ($this->schema->getFilters() as $filter) {
            $name = $filter->getName();

            if (array_key_exists($name, $requested)) {
                $resolved[$name] = $requested[$name];

                continue;
            }

            // A default fills genuine silence and nothing else. `$requested`
            // being empty is not silence: it is also what "the user cleared
            // every filter" looks like, and treating the two the same is why
            // a default used to be impossible to clear — clear it, and it
            // came straight back on the next render.
            if ($filter->hasDefault() && ! $specified) {
                $resolved[$name] = $filter->getDefault();
            }
        }

        return $resolved;
    }

    /**
     * The filter map, remembered when the table asks for it.
     *
     * Not memoized, and it does not need to be: the class is readonly, and
     * the session write here is idempotent — it stores what the request said,
     * which does not change between two reads of the same request.
     *
     * Persisted as a whole rather than per filter: "which filters are set" is
     * one decision, and remembering them individually would make removing one
     * indistinguishable from never having set it.
     *
     * `specified` is the half that defaults depend on, and it is deliberately
     * not `$filters !== []`. Three different situations produce an empty map
     * and they do not mean the same thing:
     *
     * - The request never mentioned filters and nothing was remembered. That
     *   is silence, and a default belongs there.
     * - The request said `filters=` — the only way a query string can spell
     *   "filters, and there are none". That is a choice, and a default must
     *   not undo it.
     * - Nothing was requested but the session remembers an empty map, because
     *   the user cleared the filters on a previous visit. Also a choice.
     *
     * @return array{filters: array<string, mixed>, specified: bool}
     */
    private function readFilterParams(): array
    {
        $raw = $this->namespaced('filters');
        $present = $this->namespacedPresent('filters');
        $filters = is_array($raw) ? $raw : [];

        $enabled = $this->schema->persistsFiltersInSession();

        if (! $enabled || $this->sessionKey === null || ! $this->request->hasSession()) {
            return ['filters' => $this->stringKeyed($filters), 'specified' => $present];
        }

        $store = $this->sessionKey.'.filters';

        if ($present) {
            $this->request->session()->put($store, $filters);

            return ['filters' => $this->stringKeyed($filters), 'specified' => true];
        }

        $remembered = $this->request->session()->get($store);

        return [
            'filters' => $this->stringKeyed(is_array($remembered) ? $remembered : []),
            // A stored entry is a record that the user has been here and made
            // a choice — including the choice to clear everything.
            'specified' => is_array($remembered),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $filters
     * @return array<string, mixed>
     */
    private function stringKeyed(array $filters): array
    {
        $keyed = [];

        foreach ($filters as $name => $value) {
            if (is_string($name)) {
                $keyed[$name] = $value;
            }
        }

        return $keyed;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applySort(Builder $query): void
    {
        // Rows have to arrive together to be shown together, so an active
        // group orders before anything else the table is sorted by.
        $this->activeGroup()?->applySort($query);

        $column = $this->sortColumn();

        if ($column !== null) {
            // A column that declared its own ordering owns it outright: the
            // schema could not have expressed a `CASE` or a JSON path as a
            // column name.
            if ($column->hasCustomSort()) {
                $column->applyCustomSort($query, $this->direction());

                return;
            }

            // A relation sort orders by a correlated subquery rather than by
            // a column of this table, so the column owns the strategy.
            if ($column->getSortRelation() !== null) {
                $column->applyRelationshipSort($query, $this->direction());

                return;
            }

            $query->orderBy($column->getSortColumn(), $this->direction()->value);

            return;
        }

        $default = $this->schema->getDefaultSortColumn();

        // An explicit default beats implicit insertion order, which is not
        // stable across databases.
        $query->orderBy(
            $default ?? $query->getModel()->getKeyName(),
            $this->schema->getDefaultSortDirection()->value,
        );
    }

    /**
     * The band the table is currently arranged by, or null.
     *
     * Validated against the schema like every other piece of state: a group
     * the table did not declare is ignored rather than becoming an `order by`
     * on a column named by the request.
     */
    public function activeGroup(): ?Group
    {
        $requested = $this->persistedParam('group', $this->schema->persistsSortInSession());

        if ($requested !== null) {
            // An explicit empty value turns grouping off, which is how a
            // table with a default group can be ungrouped.
            return $requested === '' ? null : $this->schema->getGroup($requested);
        }

        $default = $this->schema->getDefaultGroup();

        return $default === null ? null : $this->schema->getGroup($default);
    }

    private function search(): ?string
    {
        $term = $this->persistedParam('search', $this->schema->persistsSearchInSession());

        if ($term === null) {
            return null;
        }

        $term = trim($term);

        return $term === '' ? null : mb_substr($term, 0, 255);
    }

    /**
     * Null when the requested column is absent, unknown, or not declared
     * sortable.
     */
    private function sortColumn(): ?Column
    {
        $requested = $this->persistedParam('sort', $this->schema->persistsSortInSession());

        if ($requested === null) {
            return null;
        }

        $column = $this->schema->getColumn($requested);

        return $column?->isSortable() === true ? $column : null;
    }

    private function direction(): SortDirection
    {
        return SortDirection::fromRequest(
            $this->persistedParam('direction', $this->schema->persistsSortInSession()),
        );
    }

    /**
     * Clamped to the declared options, so `?perPage=100000` cannot ask the
     * database for every row.
     */
    private function perPage(): int
    {
        $requested = $this->param('perPage');
        $options = $this->schema->getPerPageOptions();

        if ($requested !== null) {
            $value = (int) $requested;

            if (in_array($value, $options, true)) {
                return $value;
            }
        }

        return $this->schema->getDefaultPerPage();
    }

    /**
     * Which of the table's layouts is drawn.
     *
     * Whitelisted against what the schema declared, like every other piece of
     * state: `?layout=kanban` — or `?layout=grid` on a table with no card
     * face — is ignored rather than reaching a renderer with nothing to draw
     * with. `persistedParam()` re-validates on read, so a session remembering
     * `grid` for a table that has since dropped `cards()` is discarded here
     * exactly as a hand-typed one is.
     *
     * Remembered under the column-arrangement flag rather than one of its
     * own. How a list is drawn and which columns are drawn are one decision —
     * both are presentation, both belong to the user rather than to the
     * record — the same reasoning `activeGroup()` uses to ride along with
     * `persistsSortInSession()`.
     */
    private function layout(): TableLayout
    {
        $available = $this->schema->availableLayouts();

        $requested = $this->persistedParam(
            'layout',
            $this->schema->persistsColumnsInSession(),
        );

        if ($requested !== null) {
            $layout = TableLayout::tryFrom($requested);

            if ($layout !== null && in_array($layout, $available, true)) {
                return $layout;
            }
        }

        // Not dead: `availableLayouts()` drops Grid for a reorderable table
        // even when a card face was declared, so a schema doing both resolves
        // to Table rather than to a layout it does not offer.
        $default = $this->schema->getDefaultLayout();

        return in_array($default, $available, true) ? $default : TableLayout::Table;
    }

    private function page(): int
    {
        $requested = $this->param('page');

        return max(1, $requested === null ? 1 : (int) $requested);
    }

    /**
     * Only filters that were recognised and accepted, so the frontend never
     * shows a filter chip the query did not actually apply.
     *
     * @return array<string, mixed>
     */
    private function activeFilters(): array
    {
        $active = [];

        foreach ($this->resolvedFilters() as $name => $value) {
            $filter = $this->schema->getFilter($name);

            if ($filter?->sanitize($value) !== null) {
                $active[$name] = $value;
            }
        }

        return $active;
    }

    /**
     * What is narrowing the table, said in words.
     *
     * Built on the server because only a filter knows what its value means:
     * `1` is "Verified", not "1".
     *
     * @return list<array{name: string, label: string}>
     */
    private function filterIndicators(): array
    {
        $indicators = [];

        foreach ($this->resolvedFilters() as $name => $value) {
            $label = $this->schema->getFilter($name)?->indicator($value);

            if ($label !== null) {
                $indicators[] = ['name' => $name, 'label' => $label];
            }
        }

        return $indicators;
    }

    /**
     * Which columns are shown, and in what order.
     *
     * Both are validated against the schema: an unknown name is dropped, and
     * a column the table did not make toggleable stays visible however the
     * request asks — it is the one that identifies the record, and a table
     * without it is a list of anonymous rows.
     *
     * @return array{visible: list<string>, order: list<string>}
     */
    private function columnState(): array
    {
        $known = $this->schema->columnNames();
        $toggleable = $this->schema->toggleableColumnNames();
        $requested = $this->persistedColumnState();

        $order = [];

        foreach ($this->stringList($requested['order'] ?? null) as $name) {
            if (in_array($name, $known, true) && ! in_array($name, $order, true)) {
                $order[] = $name;
            }
        }

        // Anything the arrangement did not mention keeps its declared place,
        // so adding a column to a table does not leave it invisible for
        // everyone who had already arranged the old ones.
        foreach ($known as $name) {
            if (! in_array($name, $order, true)) {
                $order[] = $name;
            }
        }

        $visible = array_key_exists('visible', $requested)
            ? array_values(array_intersect(
                $this->stringList($requested['visible']),
                $known,
            ))
            : $this->schema->defaultVisibleColumnNames();

        foreach ($known as $name) {
            if (! in_array($name, $toggleable, true) && ! in_array($name, $visible, true)) {
                $visible[] = $name;
            }
        }

        return [
            'visible' => array_values(array_intersect($order, $visible)),
            'order' => $order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function persistedColumnState(): array
    {
        $raw = $this->namespaced('columns');
        $present = $this->namespacedPresent('columns');
        $columns = is_array($raw) ? $this->stringKeyed($raw) : [];

        $enabled = $this->schema->persistsColumnsInSession();

        if (! $enabled || $this->sessionKey === null || ! $this->request->hasSession()) {
            return $columns;
        }

        $store = $this->sessionKey.'.columns';

        if ($present) {
            $this->request->session()->put($store, $columns);

            return $columns;
        }

        $remembered = $this->request->session()->get($store);

        return is_array($remembered) ? $this->stringKeyed($remembered) : [];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $names = [];

        foreach ($value as $entry) {
            if (is_string($entry)) {
                $names[] = $entry;
            }
        }

        return $names;
    }

    /**
     * Only the column searches that were recognised and accepted, so the
     * frontend never shows a search box holding a term the query ignored.
     *
     * @return array<string, string>
     */
    private function activeColumnSearches(): array
    {
        $active = [];

        foreach ($this->columnSearchParams() as $name => $value) {
            if (! is_string($name) || ! is_string($value) || trim($value) === '') {
                continue;
            }

            if ($this->schema->getIndividuallySearchableColumn($name) !== null) {
                $active[$name] = $value;
            }
        }

        return $active;
    }

    /**
     * Escapes the LIKE wildcards so a term containing `%` or `_` matches
     * literally instead of scanning the whole table.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
