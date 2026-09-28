<?php

declare(strict_types=1);

namespace PandaPanel\Resources\Pages;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Support\Breadcrumb;
use PandaPanel\Tables\Group;
use PandaPanel\Tables\Tab;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;
use PandaPanel\Widgets\PageContext;

/**
 * The index page of a resource.
 *
 * Owns authorization, the table schema, the URL-driven query, and the
 * serialized rows. It never builds its own query: it starts from the
 * resource's `query()` so the resource scope always applies.
 */
abstract class ListRecords extends ResourcePage
{
    protected static string $component = 'panel/resources/Index';

    public function render(Request $request): Response
    {
        $resource = static::$resource;

        abort_unless($resource::canViewAny(), 403);

        $schema = $resource::table(TableSchema::make());

        // Keyed by panel and slug, never by anything from the request: a
        // session key a caller could influence would let one table read
        // another's remembered state.
        $tableQuery = new TableQuery(
            $schema,
            $request,
            sessionKey: sprintf('panel.%s.table.%s', $this->panel()->getId(), $resource::slug()),
        );

        $tab = $this->activeTab($request);
        $scoped = fn (): Builder => $tab === null
            ? static::$resource::query()
            : $tab->apply(static::$resource::query());

        // The builder paginate() was given now carries every constraint it
        // applied, so summaries aggregate exactly what the page is a page of
        // — without building the same constrained query a second time.
        $query = $scoped();
        $records = $tableQuery->paginate($query);

        // Resolved once: `state()` re-reads the request and re-validates the
        // arrangement, and four props want the same answer.
        $state = $tableQuery->state();
        $visible = $state['columns']['visible'];

        return Inertia::render(static::$component, [
            'page' => $this->pageMetadata(),
            'resource' => $this->resourceMetadata(),
            'table' => $schema->toArray(),
            'state' => $state,
            'tabs' => $this->serializeTabs($tab),
            // Scoped to the tab: a widget counts what the user is looking at,
            // not the whole table.
            ...$this->widgetProps(PageContext::forQuery($scoped)),
            // Serialize what the arrangement actually shows. A hidden column
            // costs nothing to read, format, aggregate or send.
            'rows' => $this->rows($schema, $records, $tableQuery->activeGroup(), $visible),
            'summaries' => $schema->hasSummaries()
                ? $schema->summaries($query, array_values($records->items()), $visible)
                : [],
            'groupSummaries' => $tableQuery->activeGroup() === null
                ? []
                : $schema->groupSummaries(
                    $query,
                    array_values($records->items()),
                    $tableQuery->activeGroup(),
                    $visible,
                ),
            'pagination' => $this->pagination($records),
            'actionEndpoints' => $this->actionEndpoints(),
        ]);
    }

    /**
     * The filter tabs above the table, keyed by the value they take in the
     * URL. Empty means no tabs, which is the default.
     *
     * @return array<string, Tab>
     */
    public function tabs(): array
    {
        return [];
    }

    /**
     * The tab the request asked for, the first one otherwise.
     *
     * An unknown key falls back rather than erroring, exactly as an unknown
     * sort column does: the query string is user input.
     */
    private function activeTab(Request $request): ?Tab
    {
        $tabs = $this->tabs();

        if ($tabs === []) {
            return null;
        }

        $requested = $request->query('tab');

        return is_string($requested) && array_key_exists($requested, $tabs)
            ? $tabs[$requested]
            : reset($tabs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeTabs(?Tab $active): array
    {
        return array_values(array_map(
            static fn (Tab $tab): array => $tab->toArray($active !== null && $tab->key === $active->key),
            $this->tabs(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function pageMetadata(): array
    {
        $resource = static::$resource;

        return [
            ...$this->headingMetadata(),
            'breadcrumbs' => $this->serializeBreadcrumbs([
                Breadcrumb::make(__('panda-panel::pages.dashboard.title'))->url($this->dashboardUrl()),
                ...$this->parentBreadcrumbs(),
                Breadcrumb::make($resource::pluralLabel())->current(),
            ]),
            'headerActions' => $this->headerActions(),
            'scope' => static::renderHookScope(),
            'cluster' => $this->clusterNavigation(),
        ];
    }

    /**
     * A create button appears only when the resource declares a create page
     * and the policy allows it.
     *
     * @return list<array<string, mixed>>
     */
    protected function headerActions(): array
    {
        $resource = static::$resource;

        if (! array_key_exists('create', $resource::pages()) || ! $resource::canCreate()) {
            return [];
        }

        return [[
            'name' => 'create',
            'label' => 'New '.$resource::label(),
            'icon' => 'plus',
            'variant' => ActionVariant::Default->value,
            'type' => 'link',
            'url' => $resource::url('create'),
            'confirmation' => null,
        ]];
    }

    /**
     * @param  LengthAwarePaginator<int, covariant Model>  $records
     * @return list<array<string, mixed>>
     */
    protected function rows(
        TableSchema $schema,
        LengthAwarePaginator $records,
        ?Group $group = null,
        ?array $visible = null,
    ): array {
        return array_values(array_map(
            static fn (Model $record): array => $schema->toRow($record, $group, $visible),
            $records->items(),
        ));
    }

    /**
     * Only the counters the frontend needs. The paginator's own link array is
     * deliberately not sent: the frontend builds URLs from the current query
     * string, which keeps the URL the single source of truth.
     *
     * @param  LengthAwarePaginator<int, covariant Model>  $records
     * @return array<string, int>
     */
    protected function pagination(LengthAwarePaginator $records): array
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
}
