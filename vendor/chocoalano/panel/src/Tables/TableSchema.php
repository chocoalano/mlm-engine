<?php

declare(strict_types=1);

namespace PandaPanel\Tables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use PandaPanel\Actions\Action;
use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Forms\Layouts\Callout;
use PandaPanel\Support\EagerLoadPaths;
use PandaPanel\Tables\Columns\Column;
use PandaPanel\Tables\Enums\ColumnPin;
use PandaPanel\Tables\Enums\RecordActionsPosition;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\Enums\TableLayout;
use PandaPanel\Tables\Filters\Filter;
use PandaPanel\Tables\Filters\QueryBuilderFilter;

/**
 * The declarative description of a resource table.
 *
 * The schema is the whitelist. Sorting, searching, and filtering read what a
 * column or filter declared here and ignore everything else in the URL, so a
 * request parameter can never widen what the query is allowed to touch.
 */
final class TableSchema
{
    /** @var list<Column> */
    private array $columns = [];

    /** @var list<Filter> */
    private array $filters = [];

    /** @var list<Group> */
    private array $groups = [];

    private ?string $defaultGroup = null;

    private ?string $defaultSortColumn = null;

    private SortDirection $defaultSortDirection = SortDirection::Descending;

    /** @var list<int> */
    private array $perPageOptions = [10, 25, 50, 100];

    private int $defaultPerPage = 25;

    private ?string $searchPlaceholder = null;

    /** Names the ordering a table falls back to, for a UI that shows it. */
    private ?string $defaultSortOptionLabel = null;

    /**
     * How long the frontend waits after a keystroke before asking.
     *
     * A search is a query, and a query per character is a query per
     * character. Zero means ask immediately, which only makes sense for a
     * table small enough that it does not matter.
     */
    private int $searchDebounce = 300;

    /**
     * Search when the field loses focus rather than while typing.
     *
     * For a table where each search is expensive enough that the user should
     * finish the thought first.
     */
    private bool $searchOnBlur = false;

    /**
     * Whether a multi-word term is split and each word matched separately.
     *
     * On by default, because "ada lovelace" across a first-name and a
     * last-name column finds nothing as one string and the obvious record as
     * two words. Turn it off for a column where the phrase is the value —
     * a reference, a serial, an address.
     */
    private bool $splitSearchTerms = true;

    private bool $persistsSearchInSession = false;

    private bool $persistsSortInSession = false;

    private bool $persistsFiltersInSession = false;

    /**
     * Whether filter changes wait for an explicit apply.
     *
     * Off by default — a filter that takes effect as you set it is the
     * simpler thing to reason about. Turn it on for a table where each
     * request is expensive enough that half-built criteria should not be run.
     */
    private bool $defersFilters = false;

    private ?string $filtersTriggerLabel = null;

    private ?string $filtersTriggerIcon = null;

    private ?string $filtersApplyLabel = null;

    private ?string $filtersResetLabel = null;

    private bool $showsFiltersResetAction = true;

    private bool $persistsColumnsInSession = false;

    /** Whether the user may drag the columns into a different order. */
    private bool $reorderableColumns = false;

    private bool $defersColumnManager = false;

    private ?string $columnManagerTriggerLabel = null;

    private ?string $columnManagerTriggerIcon = null;

    private bool $showsColumnManagerReset = true;

    /**
     * Whether the manager opens as a dialog rather than a popover.
     *
     * A long column list is easier to work in with the page dimmed behind it,
     * and reordering by dragging needs the room.
     */
    private bool $columnManagerInModal = false;

    /** The card face, when the table declared one. */
    private ?CardLayout $cardLayout = null;

    private TableLayout $defaultLayout = TableLayout::Table;

    /**
     * The resolved card face, and whether it has been resolved.
     *
     * Two properties because `null` is a real answer — a table with no card
     * layout — and would otherwise be indistinguishable from "not asked yet".
     *
     * @var array<string, mixed>|null
     */
    private ?array $cardFace = null;

    private bool $cardFaceResolved = false;

    /**
     * Which columns a row carries, keyed by the arrangement that produced it.
     *
     * `toRow()` runs once per record and the answer depends only on the
     * arrangement, which does not change within a page.
     *
     * @var array<string, list<Column>>
     */
    private array $serializedColumns = [];

    /*
     * Null rather than the sentence itself, because a property default is
     * evaluated before the translator can answer. What "no default" means is
     * decided where it is read, not where it is declared.
     */
    /**
     * A sentence about the table, shown above it.
     *
     * For what a reader needs to know before reading the rows — what the
     * list covers, what it deliberately leaves out, how current it is. Not
     * for anything conditional or urgent: that is what a callout is.
     */
    private ?string $description = null;

    /**
     * Notices shown above the table.
     *
     * @var list<Callout>
     */
    private array $callouts = [];

    private ?string $emptyStateHeading = null;

    private ?string $emptyStateDescription = null;

    private ?string $emptyStateIcon = null;

    private bool $selectable = false;

    /** The column an arranged order is written to, or null for no reordering. */
    private ?string $reorderColumn = null;

    /** @var list<Action> */
    private array $recordActions = [];

    /** @var list<Action> */
    private array $bulkActions = [];

    /** @var list<Action> */
    private array $headerActions = [];

    /** @var list<Action> */
    private array $toolbarActions = [];

    /** @var list<Action> */
    private array $emptyStateActions = [];

    private RecordActionsPosition $recordActionsPosition = RecordActionsPosition::AfterColumns;

    /**
     * Whether the row actions stay in view while the table scrolls sideways.
     *
     * Off by default: it costs horizontal room, and a table narrow enough not
     * to scroll gains nothing from it.
     */
    private bool $frozenActions = false;

    private ?string $recordActionsLabel = null;

    /** A component key for a table that draws its own empty state. */
    private ?string $emptyStateComponent = null;

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param  array<array-key, Column>  $columns
     */
    public function columns(array $columns): self
    {
        $this->columns = array_values($columns);

        self::assertUniqueNames(
            array_map(static fn (Column $column): string => $column->getName(), $this->columns),
            PanelSchemaException::duplicateColumns(...),
        );

        return $this->forgetResolved();
    }

    /**
     * Drops what was memoized from the columns and the card layout.
     *
     * The builder is fluent, so `columns()` and `cards()` can be called after
     * something has already asked for a row — a resource that configures the
     * schema in two steps, a test that reuses one. Memoizing without this
     * would answer the second call with the first call's schema, which is the
     * quiet kind of wrong.
     */
    private function forgetResolved(): self
    {
        $this->cardFace = null;
        $this->cardFaceResolved = false;
        $this->serializedColumns = [];

        return $this;
    }

    /**
     * Refuses a set that names the same thing twice.
     *
     * At the setter rather than at serialization, so the stack trace points at
     * the line in the resource that declared it — which is the only place the
     * mistake can be fixed.
     *
     * @param  list<string>  $names
     * @param  callable(list<string>): PanelSchemaException  $exception
     */
    private static function assertUniqueNames(array $names, callable $exception): void
    {
        $duplicates = array_values(array_unique(array_diff_assoc($names, array_unique($names))));

        if ($duplicates !== []) {
            throw $exception($duplicates);
        }
    }

    /**
     * @param  list<Action>  $actions
     */
    private static function assertUsableActions(string $set, array $actions): void
    {
        self::assertUniqueNames(
            array_map(static fn (Action $action): string => $action->getName(), $actions),
            static fn (array $names): PanelSchemaException => PanelSchemaException::duplicateActions($set, $names),
        );

        foreach ($actions as $action) {
            if ($action->isInert()) {
                throw PanelSchemaException::inertAction($action->getName());
            }
        }
    }

    /**
     * @param  array<array-key, Filter>  $filters
     */
    public function filters(array $filters): self
    {
        $this->filters = array_values($filters);

        // Filter state travels in the query string keyed by name, so two
        // filters with one name are one filter the table cannot tell apart —
        // and the second one's control writes over the first one's value.
        self::assertUniqueNames(
            array_map(static fn (Filter $filter): string => $filter->getName(), $this->filters),
            PanelSchemaException::duplicateFilters(...),
        );

        return $this;
    }

    /**
     * The ways this table can band its rows.
     *
     * @param  array<array-key, Group>  $groups
     */
    public function groups(array $groups): self
    {
        $this->groups = array_values($groups);

        return $this;
    }

    public function defaultGroup(string $name): self
    {
        $this->defaultGroup = $name;

        return $this;
    }

    /**
     * @return list<Group>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    public function getGroup(string $name): ?Group
    {
        foreach ($this->groups as $group) {
            if ($group->getName() === $name) {
                return $group;
            }
        }

        return null;
    }

    public function getDefaultGroup(): ?string
    {
        return $this->defaultGroup;
    }

    public function defaultSort(string $column, SortDirection $direction = SortDirection::Descending): self
    {
        $this->defaultSortColumn = $column;
        $this->defaultSortDirection = $direction;

        return $this;
    }

    /**
     * @param  list<int>  $options
     */
    public function perPageOptions(array $options): self
    {
        $this->perPageOptions = array_values(array_unique(array_filter(
            $options,
            static fn (int $option): bool => $option > 0,
        )));

        sort($this->perPageOptions);

        return $this;
    }

    public function defaultPerPage(int $perPage): self
    {
        $this->defaultPerPage = $perPage;

        return $this;
    }

    public function searchPlaceholder(string $placeholder): self
    {
        $this->searchPlaceholder = $placeholder;

        return $this;
    }

    public function defaultSortOptionLabel(string $label): self
    {
        $this->defaultSortOptionLabel = $label;

        return $this;
    }

    public function searchDebounce(int $milliseconds): self
    {
        $this->searchDebounce = max(0, $milliseconds);

        return $this;
    }

    public function searchOnBlur(bool $onBlur = true): self
    {
        $this->searchOnBlur = $onBlur;

        return $this;
    }

    public function splitSearchTerms(bool $split = true): self
    {
        $this->splitSearchTerms = $split;

        return $this;
    }

    /**
     * Remembers the search term between visits, per user.
     *
     * Off by default: coming back to a table and finding it filtered by
     * something typed yesterday is surprising unless the table is one
     * somebody works in rather than passes through.
     */
    public function persistSearchInSession(bool $persist = true): self
    {
        $this->persistsSearchInSession = $persist;

        return $this;
    }

    public function persistSortInSession(bool $persist = true): self
    {
        $this->persistsSortInSession = $persist;

        return $this;
    }

    public function persistFiltersInSession(bool $persist = true): self
    {
        $this->persistsFiltersInSession = $persist;

        return $this;
    }

    /**
     * Holds filter changes until an apply action is used.
     */
    public function deferFilters(bool $defer = true): self
    {
        $this->defersFilters = $defer;

        return $this;
    }

    public function filtersTrigger(string $label, ?string $icon = null): self
    {
        $this->filtersTriggerLabel = $label;
        $this->filtersTriggerIcon = $icon;

        return $this;
    }

    public function filtersApplyLabel(string $label): self
    {
        $this->filtersApplyLabel = $label;

        return $this;
    }

    public function filtersResetLabel(string $label): self
    {
        $this->filtersResetLabel = $label;

        return $this;
    }

    /**
     * A table whose filters are the point — a report that is meaningless
     * unfiltered — can drop the offer to remove them all.
     */
    public function showFiltersResetAction(bool $show = true): self
    {
        $this->showsFiltersResetAction = $show;

        return $this;
    }

    public function persistColumnsInSession(bool $persist = true): self
    {
        $this->persistsColumnsInSession = $persist;

        return $this;
    }

    /**
     * Lets the user rearrange the columns.
     *
     * Distinct from `reorderable()`, which arranges *rows* and writes an
     * order column. This one is presentation and is never persisted to the
     * database: which columns somebody wants to see is theirs, not the
     * record's.
     */
    public function reorderableColumns(bool $reorderable = true): self
    {
        $this->reorderableColumns = $reorderable;

        return $this;
    }

    public function deferColumnManager(bool $defer = true): self
    {
        $this->defersColumnManager = $defer;

        return $this;
    }

    public function columnManagerTrigger(string $label, ?string $icon = null): self
    {
        $this->columnManagerTriggerLabel = $label;
        $this->columnManagerTriggerIcon = $icon;

        return $this;
    }

    public function columnManagerInModal(bool $inModal = true): self
    {
        $this->columnManagerInModal = $inModal;

        return $this;
    }

    public function showColumnManagerReset(bool $show = true): self
    {
        $this->showsColumnManagerReset = $show;

        return $this;
    }

    public function persistsColumnsInSession(): bool
    {
        return $this->persistsColumnsInSession;
    }

    public function hasReorderableColumns(): bool
    {
        return $this->reorderableColumns;
    }

    /**
     * The columns a user is allowed to hide.
     *
     * A column that is not toggleable cannot be hidden however the request
     * asks — it is the one that identifies the record, and a table without it
     * is a list of anonymous rows.
     *
     * @return list<string>
     */
    public function toggleableColumnNames(): array
    {
        return array_values(array_map(
            static fn (Column $column): string => $column->getName(),
            array_filter(
                $this->columns,
                static fn (Column $column): bool => $column->isToggleable(),
            ),
        ));
    }

    /**
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_map(
            static fn (Column $column): string => $column->getName(),
            $this->columns,
        );
    }

    /**
     * The columns visible before anybody changes anything.
     *
     * @return list<string>
     */
    public function defaultVisibleColumnNames(): array
    {
        return array_values(array_map(
            static fn (Column $column): string => $column->getName(),
            array_filter(
                $this->columns,
                static fn (Column $column): bool => $column->isVisible(),
            ),
        ));
    }

    public function persistsFiltersInSession(): bool
    {
        return $this->persistsFiltersInSession;
    }

    public function defersFilters(): bool
    {
        return $this->defersFilters;
    }

    /**
     * The values every filter starts on, for filters that declared one.
     *
     * @return array<string, mixed>
     */
    public function defaultFilters(): array
    {
        $defaults = [];

        foreach ($this->filters as $filter) {
            if ($filter->hasDefault()) {
                $defaults[$filter->getName()] = $filter->getDefault();
            }
        }

        return $defaults;
    }

    public function shouldSplitSearchTerms(): bool
    {
        return $this->splitSearchTerms;
    }

    public function persistsSearchInSession(): bool
    {
        return $this->persistsSearchInSession;
    }

    public function persistsSortInSession(): bool
    {
        return $this->persistsSortInSession;
    }

    /**
     * The columns that carry their own search box.
     *
     * @return list<Column>
     */
    public function getIndividuallySearchableColumns(): array
    {
        return array_values(array_filter(
            $this->columns,
            static fn (Column $column): bool => $column->isIndividuallySearchable(),
        ));
    }

    public function getIndividuallySearchableColumn(string $name): ?Column
    {
        $column = $this->getColumn($name);

        return $column?->isIndividuallySearchable() === true ? $column : null;
    }

    /**
     * A sentence shown with the table, before its rows.
     *
     * Plain text rather than a closure: it describes the table, not a
     * record, so there is nothing per-render for a closure to read. Pass a
     * translated string — `__()` in the resource is where the application's
     * own copy belongs, and the package translates only its own.
     */
    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Adds a notice above the table.
     *
     * The same `Callout` a form section uses, so a warning looks the same
     * wherever the panel shows one and there is one component to keep
     * accessible rather than two that drift.
     *
     * Callouts accumulate. A table that has something to say usually has one
     * thing to say, but "the period is locked" and "3 records need attention"
     * are two facts and stacking them is better than an API that has to be
     * replaced the first time both are true.
     */
    public function callout(Callout $callout): self
    {
        $this->callouts[] = $callout;

        return $this;
    }

    /**
     * @param  array<array-key, Callout>  $callouts
     */
    public function callouts(array $callouts): self
    {
        $this->callouts = array_values($callouts);

        return $this;
    }

    /**
     * @return list<Callout>
     */
    public function getCallouts(): array
    {
        return $this->callouts;
    }

    public function emptyState(string $heading, ?string $description = null, ?string $icon = null): self
    {
        $this->emptyStateHeading = $heading;
        $this->emptyStateDescription = $description;
        $this->emptyStateIcon = $icon;

        return $this;
    }

    /**
     * Row selection only makes sense once there are bulk actions to run on
     * the selection, so it is off until a table opts in.
     */
    /**
     * Lets the user drag rows into a new order, persisted to `$column`.
     *
     * Turning this on also fixes the sort: an order the user arranges is
     * only meaningful when the table is showing that order, so the column
     * becomes the default sort ascending.
     */
    public function reorderable(string $column): self
    {
        $this->reorderColumn = $column;

        return $this->defaultSort($column, SortDirection::Ascending);
    }

    public function getReorderColumn(): ?string
    {
        return $this->reorderColumn;
    }

    public function isReorderable(): bool
    {
        return $this->reorderColumn !== null;
    }

    /**
     * Lets the table also be drawn as a grid of cards.
     *
     * The whole opt-in, and it takes no required argument: called bare, the
     * face is inferred from the columns the table already declares. Every
     * table that does not call this keeps `layouts: ['table']`, which is what
     * makes the layout toggle render nothing at all where it was not asked
     * for.
     */
    public function cards(?CardLayout $layout = null): self
    {
        $this->cardLayout = $layout ?? CardLayout::make();

        return $this->forgetResolved();
    }

    /**
     * Which layout the table opens in, before the user has chosen.
     */
    public function defaultLayout(TableLayout $layout): self
    {
        $this->defaultLayout = $layout;

        return $this->forgetResolved();
    }

    public function getDefaultLayout(): TableLayout
    {
        return $this->defaultLayout;
    }

    /**
     * The card face, or null for a table that has none.
     *
     * `defaultLayout(Grid)` on its own counts as declaring one. A table that
     * says it opens as cards has said it has cards, and the inferred face is
     * exactly what `cards()` would have produced — the alternative is a line
     * of configuration that silently does nothing.
     */
    private function resolvedCardLayout(): ?CardLayout
    {
        if ($this->cardLayout !== null) {
            return $this->cardLayout;
        }

        return $this->defaultLayout === TableLayout::Grid ? CardLayout::make() : null;
    }

    /**
     * The layouts this table can be drawn in, always with `Table` first.
     *
     * A reorderable table offers only the table. An order the user arranges
     * by dragging is a linear one, and dragging a card into place in a grid
     * that wraps is a different interaction needing a different affordance —
     * offering a layout that cannot do the thing reordering was turned on for
     * is worse than not offering it.
     *
     * Sent to the frontend as the answer rather than as the rule, so nothing
     * on that side has to re-derive it.
     *
     * @return list<TableLayout>
     */
    public function availableLayouts(): array
    {
        if ($this->isReorderable() || $this->resolvedCardLayout() === null) {
            return [TableLayout::Table];
        }

        return [TableLayout::Table, TableLayout::Grid];
    }

    public function selectable(bool $selectable = true): self
    {
        $this->selectable = $selectable;

        return $this;
    }

    /**
     * Actions offered on each row.
     *
     * @param  array<array-key, Action>  $actions
     */
    public function recordActions(array $actions): self
    {
        $this->recordActions = array_values($actions);

        self::assertUsableActions('row actions', $this->recordActions);

        return $this;
    }

    /**
     * Actions offered on a selection. Declaring any of these turns row
     * selection on, since a bulk action with no way to select is useless.
     *
     * @param  array<array-key, Action>  $actions
     */
    public function bulkActions(array $actions): self
    {
        $this->bulkActions = array_values($actions);

        self::assertUsableActions('bulk actions', $this->bulkActions);

        if ($this->bulkActions !== []) {
            $this->selectable = true;
        }

        return $this;
    }

    /**
     * Actions in the page header, above the table.
     *
     * They act on the table rather than on a row, so they are resolved
     * without a record: authorization is asked with null, exactly as it is
     * for a bulk action before anything is selected.
     *
     * @param  array<array-key, Action>  $actions
     */
    public function headerActions(array $actions): self
    {
        $this->headerActions = array_values($actions);

        self::assertUsableActions('header actions', $this->headerActions);

        return $this;
    }

    /**
     * Actions in the toolbar, beside the search box.
     *
     * Separate from header actions because the two read differently: a header
     * action is about the thing the page is for, a toolbar action is about
     * the view of it.
     *
     * @param  array<array-key, Action>  $actions
     */
    public function toolbarActions(array $actions): self
    {
        $this->toolbarActions = array_values($actions);

        self::assertUsableActions('toolbar actions', $this->toolbarActions);

        return $this;
    }

    /**
     * Actions offered when there is nothing to show.
     *
     * An empty table is the one place an action is most useful and least
     * likely to be found, because every other affordance is about rows.
     *
     * @param  array<array-key, Action>  $actions
     */
    public function emptyStateActions(array $actions): self
    {
        $this->emptyStateActions = array_values($actions);

        self::assertUsableActions('empty state actions', $this->emptyStateActions);

        return $this;
    }

    /**
     * Keeps the row actions pinned to the right edge.
     *
     * The companion to `Column::frozen()` for the far side of a wide table:
     * scrolling out to column fourteen to read a value, and then having to
     * scroll back to act on the row, is the same problem in the other
     * direction.
     */
    public function frozenActions(bool $frozen = true): self
    {
        $this->frozenActions = $frozen;

        return $this;
    }

    public function hasFrozenActions(): bool
    {
        return $this->frozenActions;
    }

    /**
     * Whether anything is pinned to the leading edge.
     *
     * The reorder handle and the selection checkbox are frozen with the first
     * frozen column rather than on their own account: they sit to the left of
     * every data column, and letting them scroll while a column beside them
     * stays put would be two elements disagreeing about where the row begins.
     */
    public function hasFrozenStart(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->getFrozen() === ColumnPin::Start) {
                return true;
            }
        }

        return false;
    }

    public function recordActionsPosition(RecordActionsPosition $position): self
    {
        $this->recordActionsPosition = $position;

        return $this;
    }

    public function recordActionsLabel(string $label): self
    {
        $this->recordActionsLabel = $label;

        return $this;
    }

    /**
     * A component that replaces the whole empty state.
     *
     * A build-time registry key under
     * `resources/js/pages/Panels/{Panel}/EmptyStates/`, never markup — the
     * same rule custom columns and widgets follow.
     */
    public function emptyStateComponent(string $component): self
    {
        $this->emptyStateComponent = $component;

        return $this;
    }

    /**
     * @return list<Action>
     */
    public function getHeaderActions(): array
    {
        return $this->headerActions;
    }

    /**
     * @return list<Action>
     */
    public function getToolbarActions(): array
    {
        return $this->toolbarActions;
    }

    /**
     * @return list<Action>
     */
    public function getEmptyStateActions(): array
    {
        return $this->emptyStateActions;
    }

    /**
     * Finds a table-level action by name, across every place one can sit.
     *
     * One lookup rather than three, because the endpoint that runs them does
     * not care which bar an action was rendered in — only that the table
     * declared it somewhere.
     */
    public function getTableAction(string $name): ?Action
    {
        foreach ([...$this->headerActions, ...$this->toolbarActions, ...$this->emptyStateActions] as $action) {
            if ($action->getName() === $name) {
                return $action;
            }
        }

        return null;
    }

    /**
     * @return list<Action>
     */
    public function getRecordActions(): array
    {
        return $this->recordActions;
    }

    /**
     * @return list<Action>
     */
    public function getBulkActions(): array
    {
        return $this->bulkActions;
    }

    public function getRecordAction(string $name): ?Action
    {
        foreach ($this->recordActions as $action) {
            if ($action->getName() === $name) {
                return $action;
            }
        }

        // A column's own action is a record action in every sense that
        // matters — it names a row, authorizes it, and changes it — so the
        // endpoint finds it here rather than needing a second lookup.
        foreach ($this->columns as $column) {
            $action = $column->getAction();

            if ($action?->getName() === $name) {
                return $action;
            }
        }

        return null;
    }

    public function getBulkAction(string $name): ?Action
    {
        foreach ($this->bulkActions as $action) {
            if ($action->getName() === $name) {
                return $action;
            }
        }

        return null;
    }

    /**
     * @return list<Column>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getColumn(string $name): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->getName() === $name) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Lets every column shape the query before any row is read.
     *
     * An aggregate is computed in the select, so it has to be asked for
     * before the paginator runs — reading it per row would be a query per
     * record, which is the thing eager loading exists to prevent.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function applyColumnQueries(Builder $query): void
    {
        foreach ($this->columns as $column) {
            $column->applyQuery($query);
        }

        // A column named `author.name` reads its value through `data_get()`,
        // which loads the relation once per record unless something loaded it
        // first. `$with` was that something, and it had to be remembered.
        // Deriving it from the names the columns already carry means the
        // common case stops being a thing to remember — and adding an eager
        // load can only reduce queries, because the relation was going to be
        // read either way.
        //
        // `$with` still matters for everything with no name to read: a
        // relation reached from a `formatUsing()` closure, from
        // `recordTitle()`, or from a policy.
        $paths = EagerLoadPaths::from($query->getModel(), $this->columnNames());

        if ($paths !== []) {
            $query->with($paths);
        }
    }

    /**
     * @return list<Column>
     */
    public function getSortableColumns(): array
    {
        return array_values(array_filter(
            $this->columns,
            static fn (Column $column): bool => $column->isSortable(),
        ));
    }

    /**
     * Every column the search term may be matched against, local ones only.
     *
     * @return list<string>
     */
    public function getSearchColumns(): array
    {
        $columns = [];

        foreach ($this->searchableColumns() as $name) {
            if (! str_contains($name, '.')) {
                $columns[] = $name;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * The searchable names that point at a relation.
     *
     * Kept apart from the local ones because they need `whereHas`, not a
     * `LIKE`: `author.name` is not a column of this table, and matching it as
     * one would be a SQL error rather than an empty result.
     *
     * @return list<string>
     */
    public function getSearchRelations(): array
    {
        $relations = [];

        foreach ($this->searchableColumns() as $name) {
            if (str_contains($name, '.')) {
                $relations[] = $name;
            }
        }

        return array_values(array_unique($relations));
    }

    /**
     * @return list<string>
     */
    private function searchableColumns(): array
    {
        $names = [];

        foreach ($this->columns as $column) {
            if ($column->isSearchable()) {
                $names = [...$names, ...$column->getSearchColumns()];
            }
        }

        return $names;
    }

    public function isSearchable(): bool
    {
        return $this->getSearchColumns() !== [] || $this->getSearchRelations() !== [];
    }

    /**
     * @return list<Filter>
     */
    public function getFilters(): array
    {
        return array_map(fn (Filter $filter): Filter => $this->prepareFilter($filter), $this->filters);
    }

    public function getFilter(string $name): ?Filter
    {
        foreach ($this->filters as $filter) {
            if ($filter->getName() === $name) {
                return $this->prepareFilter($filter);
            }
        }

        return null;
    }

    /**
     * Gives a query-builder filter the table's own columns to offer.
     *
     * A filter is declared on its own and does not know which table it was
     * attached to, so the table hands its columns over here rather than the
     * filter reaching for them. Every other filter type passes through
     * untouched.
     *
     * Applied on every read rather than once at serialization: the browser
     * learns which conditions exist from `toArray()`, and the query layer
     * decides whether an incoming rule is one of them through `getFilter()`.
     * If only the first knew about the columns, every condition the user
     * built from one would be offered and then silently dropped.
     */
    private function prepareFilter(Filter $filter): Filter
    {
        if (! $filter instanceof QueryBuilderFilter) {
            return $filter;
        }

        $derived = [];

        foreach ($this->columns as $column) {
            $constraint = $column->toQueryConstraint();

            if ($constraint !== null) {
                $derived[] = $constraint;
            }
        }

        return $filter->derivedConstraints($derived);
    }

    public function getDefaultSortColumn(): ?string
    {
        return $this->defaultSortColumn;
    }

    public function getDefaultSortDirection(): SortDirection
    {
        return $this->defaultSortDirection;
    }

    /**
     * @return list<int>
     */
    public function getPerPageOptions(): array
    {
        return $this->perPageOptions;
    }

    public function getDefaultPerPage(): int
    {
        return in_array($this->defaultPerPage, $this->perPageOptions, true)
            ? $this->defaultPerPage
            : ($this->perPageOptions[0] ?? 25);
    }

    public function isSelectable(): bool
    {
        return $this->selectable;
    }

    public function hasSummaries(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->hasSummaries()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The figures under the table, keyed by column.
     *
     * Two sources, and the difference matters: a whole-result figure is
     * computed by the database over the filtered query, while a per-page one
     * is reduced from the records on screen. A "total" that changed when you
     * paged would be a different number wearing the same label, so the
     * default is the whole result and a summarizer has to ask for the page.
     *
     * @param  Builder<covariant Model>  $query  the filtered query, before pagination
     * @param  list<Model>  $records  the records on this page
     * @return array<string, list<array<string, mixed>>>
     */
    public function summaries(Builder $query, array $records, ?array $visible = null): array
    {
        $summaries = [];

        // A figure under a column nobody is looking at is an aggregate query
        // whose result is discarded — more expensive than the hidden cell that
        // was already being skipped, and the frontend only draws figures under
        // the columns it renders anyway.
        foreach ($this->serializedColumns($visible) as $column) {
            if (! $column->hasSummaries()) {
                continue;
            }

            $target = $column->summaryColumn();
            $source = null;
            $figures = [];

            foreach ($column->getSummarizers() as $summarizer) {
                if ($summarizer->isPerPage()) {
                    $figures[] = $summarizer->toArray(
                        $summarizer->summarizeRecords($records, $target),
                    );

                    continue;
                }

                // Built once per column, and only when something actually
                // needs it: a per-page figure never touches the database.
                $source ??= $this->summarySource($query, $column);

                $figures[] = $summarizer->toArray($summarizer->summarize($source, $target));
            }

            $summaries[$column->getName()] = $figures;
        }

        return $summaries;
    }

    /**
     * Actions the user may run, in the order declared.
     *
     * Resolved with no record, so authorization is asked the way it is for a
     * bulk action before anything is selected — an action refused here is
     * absent rather than rendered and then refused.
     *
     * @param  list<Action>  $actions
     * @return list<array<string, mixed>>
     */
    private function serializeActions(array $actions): array
    {
        return array_values(array_filter(array_map(
            static fn (Action $action): ?array => $action->toArray(),
            $actions,
        )));
    }

    /**
     * The query a summary aggregates over.
     *
     * Two cases, and getting them confused is a SQL error rather than a wrong
     * number. A plain column is aggregated straight from the table. A
     * generated alias — `posts_count` from `withCount()` — exists only in the
     * SELECT list, so `sum(posts_count)` is an unknown column; the sum has to
     * happen *outside* a subquery that produced it.
     *
     * The wrapping is not unconditional because it is not free: a subquery
     * around a select carrying correlated count columns would compute every
     * one of them for every row, which is exactly the cost a plain
     * `sum(total)` should not pay.
     *
     * The limit and offset are cleared either way. `paginate()` sets them on
     * the builder it was given, so a summary computed from it afterwards
     * would silently describe one page while claiming to describe the result.
     *
     * @param  Builder<covariant Model>  $query
     */
    private function summarySource(Builder $query, Column $column): QueryBuilder
    {
        $base = $query->clone()->reorder()->toBase();

        // Assigned rather than set through `limit()`, which takes an int and
        // so has no way to say "no limit at all".
        $base->limit = null;
        $base->offset = null;

        return $column->summaryUsesAggregate()
            ? DB::query()->fromSub($base, 'panel_summary_source')
            : $base;
    }

    /**
     * The figures under each band, keyed by group key then column.
     *
     * Computed per band over the *whole* filtered result for that key, not
     * over the rows of it that happen to be on this page — the same reason
     * the table's own totals are: a band total that changed when you paged
     * would be a different number wearing the same label. That costs one
     * query per band on screen, which is a handful, and a `perPage`
     * summarizer still reduces from the records shown.
     *
     * @param  Builder<covariant Model>  $query  the filtered query, before pagination
     * @param  list<Model>  $records  the records on this page
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    public function groupSummaries(Builder $query, array $records, Group $group, ?array $visible = null): array
    {
        if (! $this->hasSummaries()) {
            return [];
        }

        $bands = [];

        foreach ($records as $record) {
            $bands[$group->keyFor($record)][] = $record;
        }

        $summaries = [];

        foreach ($bands as $key => $bandRecords) {
            $bandQuery = $query->clone()->where($group->getColumn(), '=', $key);

            $summaries[$key] = $this->summaries($bandQuery, $bandRecords, $visible);
        }

        return $summaries;
    }

    /**
     * Serializes one record into the cells the frontend renders.
     *
     * Per-row actions are resolved here because visibility and
     * authorization depend on the record. An action the user may not run is
     * absent from the row rather than rendered and refused later.
     *
     * @return array{key: int|string, group: array{key: string, title: string, description: string|null}|null, cells: array<string, mixed>, cellMeta: array<string, array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    /**
     * The columns a row actually has to carry.
     *
     * Hiding a column used to reduce nothing: every column was read from the
     * record, formatted, passed through `urlUsing()` and `tooltip()`, and
     * serialized — and then the frontend drew a subset. On a wide table that
     * is most of the work and most of the payload spent on cells nobody sees,
     * and for a dotted column it was a relation loaded to be discarded.
     *
     * `null` means "no visibility state", which is every caller that has none
     * to give and every existing call: the whole set, exactly as before.
     *
     * Two columns are kept whatever the arrangement says, because a
     * [card layout](../../docs/tables/card-layout.md) draws its image and its
     * heading regardless of visibility — a card whose title was hidden by the
     * column manager would otherwise lose its heading rather than a row.
     *
     * @param  list<string>|null  $visible
     * @return list<Column>
     */
    private function serializedColumns(?array $visible): array
    {
        if ($visible === null) {
            return $this->columns;
        }

        // Memoized on the arrangement, because `toRow()` is called once per
        // record and the answer is the same for all of them. Resolving a card
        // face means validating every slot and walking the inference rules,
        // and doing that twenty-five times to get twenty-five identical
        // answers is the cost this method exists to remove.
        $key = implode("\0", $visible);

        if (array_key_exists($key, $this->serializedColumns)) {
            return $this->serializedColumns[$key];
        }

        $cards = $this->cardFace();

        $keep = array_flip(array_values(array_filter([
            ...$visible,
            $cards['image'] ?? null,
            $cards['title'] ?? null,
        ], static fn (?string $name): bool => $name !== null)));

        return $this->serializedColumns[$key] = array_values(array_filter(
            $this->columns,
            static fn (Column $column): bool => array_key_exists($column->getName(), $keep),
        ));
    }

    /**
     * The card face, resolved once for this schema.
     *
     * `toArray()` on the layout is not cheap — it validates every declared
     * slot against the columns and then runs the inference rules — and both
     * the row serializer and `TableSchema::toArray()` want the same answer.
     *
     * @return array<string, mixed>|null
     */
    private function cardFace(): ?array
    {
        if ($this->cardFaceResolved) {
            return $this->cardFace;
        }

        $this->cardFaceResolved = true;

        return $this->cardFace = $this->resolvedCardLayout()?->toArray($this->columns);
    }

    public function toRow(Model $record, ?Group $group = null, ?array $visible = null): array
    {
        $columns = $this->serializedColumns($visible);
        $cells = [];

        foreach ($columns as $column) {
            $cells[$column->getName()] = $column->toCell($record);
        }

        $actions = [];

        foreach ($this->recordActions as $action) {
            $definition = $action->toArray($record);

            if ($definition !== null) {
                $actions[] = $definition;
            }
        }

        $meta = [];

        foreach ($columns as $column) {
            $cellMeta = $column->toCellMeta($record);

            if ($cellMeta !== null) {
                $meta[$column->getName()] = $cellMeta;
            }
        }

        return [
            'key' => $record->getKey(),
            // The band this row falls in, when the table is grouped. Resolved
            // per record on the server so a key can become a name without the
            // frontend knowing how.
            'group' => $group === null ? null : [
                'key' => $group->keyFor($record),
                'title' => $group->titleFor($record),
                'description' => $group->descriptionFor($record),
            ],
            'cells' => $cells,
            // Beside the cells, not inside them: a cell value stays exactly
            // the shape its renderer's guard already narrows, and a table
            // using none of this pays nothing for it.
            'cellMeta' => $meta,
            'actions' => $actions,
        ];
    }

    /**
     * The table definition sent to Vue. Contains no closures, no query, and
     * no model class.
     *
     * @return array<string, mixed>
     */
    /**
     * The default sort column, checked against the columns that exist.
     *
     * Here rather than in `defaultSort()`, which can be called before
     * `columns()` and would then have nothing to check against. A column that
     * is not in the schema is not in the sort whitelist either, so the table
     * would quietly fall back to its natural order — the declaration read as
     * applied and was not.
     */
    private function assertKnownDefaultSort(): ?string
    {
        if ($this->defaultSortColumn === null) {
            return null;
        }

        foreach ($this->columns as $column) {
            if ($column->getName() === $this->defaultSortColumn) {
                return $this->defaultSortColumn;
            }
        }

        throw PanelSchemaException::unknownDefaultSort(
            $this->defaultSortColumn,
            array_map(static fn (Column $column): string => $column->getName(), $this->columns),
        );
    }

    public function toArray(): array
    {
        return [
            'columns' => array_map(
                static fn (Column $column): array => $column->toArray(),
                $this->columns,
            ),
            'filters' => array_map(
                fn (Filter $filter): array => $this->prepareFilter($filter)->toArray(),
                $this->filters,
            ),
            'groups' => array_map(
                static fn (Group $group): array => $group->toArray(),
                $this->groups,
            ),
            'defaultGroup' => $this->defaultGroup,
            'columnManager' => [
                'reorderable' => $this->reorderableColumns,
                'deferred' => $this->defersColumnManager,
                'triggerLabel' => $this->columnManagerTriggerLabel ?? 'Columns',
                'triggerIcon' => $this->columnManagerTriggerIcon,
                'resetLabel' => 'Reset',
                'showReset' => $this->showsColumnManagerReset,
                'modal' => $this->columnManagerInModal,
                'toggleable' => $this->toggleableColumnNames(),
            ],
            'filterBehaviour' => [
                'deferred' => $this->defersFilters,
                'triggerLabel' => $this->filtersTriggerLabel ?? __('panda-panel::tables.filters.trigger'),
                'triggerIcon' => $this->filtersTriggerIcon,
                'applyLabel' => $this->filtersApplyLabel ?? __('panda-panel::tables.filters.apply'),
                'resetLabel' => $this->filtersResetLabel ?? __('panda-panel::tables.filters.reset'),
                'showReset' => $this->showsFiltersResetAction,
            ],
            'searchable' => $this->isSearchable(),
            'searchPlaceholder' => $this->searchPlaceholder ?? __('panda-panel::tables.search.placeholder'),
            'searchDebounce' => $this->searchDebounce,
            'searchOnBlur' => $this->searchOnBlur,
            'individualSearchColumns' => array_map(
                static fn (Column $column): string => $column->getName(),
                $this->getIndividuallySearchableColumns(),
            ),
            'selectable' => $this->selectable,
            'reorderable' => $this->isReorderable(),
            // Sent as answers rather than as the rule that produced them, so
            // the frontend never has to re-derive which side is pinned.
            'frozen' => [
                'start' => $this->hasFrozenStart(),
                'actions' => $this->frozenActions,
            ],
            'perPageOptions' => $this->perPageOptions,
            'defaultPerPage' => $this->getDefaultPerPage(),
            'defaultSort' => $this->assertKnownDefaultSort() === null ? null : [
                'column' => $this->defaultSortColumn,
                'direction' => $this->defaultSortDirection->value,
                'label' => $this->defaultSortOptionLabel,
            ],
            'layouts' => array_map(
                static fn (TableLayout $layout): string => $layout->value,
                $this->availableLayouts(),
            ),
            // Column *names*, never definitions. The definitions are already
            // in `columns`; sending them twice would be two places for the
            // same column to disagree with itself.
            'cards' => $this->cardFace(),
            'bulkActions' => $this->serializeActions($this->bulkActions),
            'recordActions' => [
                'position' => $this->recordActionsPosition->value,
                'label' => $this->recordActionsLabel,
            ],
            'headerActions' => $this->serializeActions($this->headerActions),
            'toolbarActions' => $this->serializeActions($this->toolbarActions),
            'description' => $this->description,
            'callouts' => array_map(
                static fn (Callout $callout): array => $callout->toTableArray(),
                $this->callouts,
            ),
            'emptyState' => [
                'heading' => $this->emptyStateHeading
                    ?? __('panda-panel::tables.empty_state.heading'),
                'description' => $this->emptyStateDescription,
                'icon' => $this->emptyStateIcon,
                'component' => $this->emptyStateComponent,
                'actions' => $this->serializeActions($this->emptyStateActions),
            ],
        ];
    }
}
