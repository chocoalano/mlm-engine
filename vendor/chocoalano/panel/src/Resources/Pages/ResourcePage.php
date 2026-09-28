<?php

declare(strict_types=1);

namespace PandaPanel\Resources\Pages;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Enums\SubNavigationPosition;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Pages\WidgetCollection;
use PandaPanel\Resources\Concerns\HasLifecycleHooks;
use PandaPanel\Resources\RelationTable;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\Breadcrumb;
use PandaPanel\Support\ClusterNavigation;
use PandaPanel\Support\ParentRecord;
use PandaPanel\Support\RecordSubNavigation;
use PandaPanel\Widgets\PageContext;
use PandaPanel\Widgets\Support\WidgetFilters;
use PandaPanel\Widgets\Widget;

/**
 * Shared behaviour for every resource page.
 *
 * Pages are routed as `[PageClass::class, 'render']` for the GET and
 * `[PageClass::class, 'handle']` for the write verb, so each page is a real
 * controller and panel routes stay cacheable.
 */
abstract class ResourcePage
{
    use HasLifecycleHooks;

    /** @var class-string<PanelResource> */
    protected static string $resource;

    /**
     * The key this page is known by in `Resource::pages()`.
     *
     * Standard pages override this where they need to. The base default is
     * the index page because `ListRecords` is the only standard page that
     * does not already need the key for form endpoints.
     */
    protected static string $page = 'index';

    /**
     * Whether this page's write runs in a transaction.
     *
     * `null` inherits the panel. Set it to `true` or `false` on a page whose
     * write differs from the rest of the panel — one that also calls an
     * external service, say, where holding a transaction open is worse than
     * not having one.
     */
    protected static ?bool $hasDatabaseTransactions = null;

    /**
     * The path this page registers under, relative to the resource.
     *
     * Null takes the page key, which is what the four standard pages and
     * most custom ones want. Declare one to put a page on a nested path, and
     * include `{record}` for a page that operates on a single record — the
     * registrar passes it to `render()` exactly as it does for view and edit.
     */
    protected static ?string $routePath = null;

    /**
     * The document title, shown in the browser tab.
     *
     * Null takes the page's own default — the resource's plural label on an
     * index, "New {label}" on a create, the record's title on a view. Declare
     * one on the page class to override it, exactly as a standalone `Page`
     * does.
     */
    protected static ?string $title = null;

    /**
     * The heading rendered above the page's content.
     *
     * Null follows `$title`, except where a page says otherwise: an edit page
     * heads with the record rather than with "Edit {record}".
     */
    protected static ?string $heading = null;

    /**
     * The line beneath the heading. Null takes the page's default, which is
     * nothing on most pages.
     */
    protected static ?string $subheading = null;

    /**
     * What the form's submit button says.
     *
     * Null keeps the default the page already had — "Create <resource>" on a
     * create page, "Save changes" on an edit one — so a page that says nothing
     * is unaffected.
     *
     * It exists because the alternative was building a `Wizard` or a custom
     * page to change one word. A record form that ends in "Process schedule"
     * rather than "Save" is describing what saving it does, and that is the
     * page's own statement about itself, not a reason to leave the standard
     * lifecycle behind.
     */
    protected static ?string $submitLabel = null;

    /**
     * Override any of these three when the text depends on something a static
     * property cannot say — the record, the tenant, a count.
     */
    public function getTitle(?Model $record = null): string
    {
        return static::$title ?? $this->defaultTitle($record);
    }

    public function getHeading(?Model $record = null): string
    {
        return static::$heading ?? $this->defaultHeading($record);
    }

    public function getSubheading(?Model $record = null): ?string
    {
        return static::$subheading ?? $this->defaultSubheading($record);
    }

    /**
     * What the page is called when it says nothing. The resource's label is
     * the only thing every page has in common, so that is the fallback a
     * custom page inherits.
     */
    /**
     * Override for a label that depends on the record, the user, or anything
     * else only the page knows. Translate here rather than in the property:
     * a static initializer runs before the locale is resolved.
     */
    protected function getSubmitLabel(): ?string
    {
        return static::$submitLabel;
    }

    protected function defaultTitle(?Model $record): string
    {
        return static::$resource::pluralLabel();
    }

    /**
     * The heading follows the title unless a page separates the two.
     */
    protected function defaultHeading(?Model $record): string
    {
        return $this->getTitle($record);
    }

    protected function defaultSubheading(?Model $record): ?string
    {
        return null;
    }

    /**
     * The three heading keys every page's metadata carries, resolved once so
     * no page repeats the fallback logic.
     *
     * @return array{title: string, heading: string, subheading: string|null}
     */
    protected function headingMetadata(?Model $record = null): array
    {
        return [
            'title' => $this->getTitle($record),
            'heading' => $this->getHeading($record),
            'subheading' => $this->getSubheading($record),
        ];
    }

    public static function routePath(string $key): string
    {
        return static::$routePath ?? $key;
    }

    public static function hasDatabaseTransactions(): ?bool
    {
        return static::$hasDatabaseTransactions;
    }

    /**
     * Every page of a resource shares its scope, so a hook scoped to the
     * resource appears on its list, view, create, and edit pages alike.
     *
     * A slug, never a class name: nothing in page metadata may name a PHP
     * class.
     */
    public static function renderHookScope(): string
    {
        return 'resource:'.static::$resource::slug();
    }

    /**
     * Validates one step of a wizard, and nothing else.
     *
     * A separate endpoint rather than a separate definition: the rules come
     * from the same schema the whole form uses, narrowed to the fields the
     * step already says it holds. There is nothing here that could disagree
     * with the final submit.
     *
     * Answers JSON, like the search endpoint and for the same reason —
     * re-rendering the page the user is filling in would throw away what
     * they typed.
     */
    protected function validateStepFor(Request $request, FormSchema $schema, ?Model $record = null): JsonResponse
    {
        $wizard = $schema->wizard();

        abort_if($wizard === null, 400, __('panda-panel::errors.form_has_no_steps'));

        $step = (int) $request->integer('step');

        abort_unless($step >= 0 && $step < $wizard->countSteps(), 422, __('panda-panel::errors.unknown_step'));

        $rules = $schema->validationRulesForStep($step, $record);

        $validator = validator($this->beforeValidate($request->all()), $rules);

        return $validator->fails()
            ? response()->json(['errors' => $validator->errors()->toArray()], 422)
            : response()->json(['errors' => []]);
    }

    /**
     * Widgets shown above the page's own content.
     *
     * @return list<class-string<Widget>>
     */
    public function headerWidgets(): array
    {
        return static::$resource::getHeaderWidgets($this->widgetPageKey());
    }

    /**
     * @return list<class-string<Widget>>
     */
    public function footerWidgets(): array
    {
        return static::$resource::getFooterWidgets($this->widgetPageKey());
    }

    /**
     * The widget props every resource page ships.
     *
     * The context is what separates these from dashboard widgets: a list
     * page hands over its own query, a record page hands over its record, so
     * a widget counts what the user is looking at rather than the whole
     * table.
     *
     * @return array<string, mixed>
     */
    protected function widgetProps(?PageContext $context = null): array
    {
        $headerClasses = $this->headerWidgets();
        $footerClasses = $this->footerWidgets();
        $filters = $this->resolveWidgetFilters($context, $headerClasses, $footerClasses);

        $header = WidgetCollection::for($headerClasses, $context, $filters);
        $footer = WidgetCollection::for($footerClasses, $context, $filters);

        return [
            'headerWidgets' => $header->definitions(),
            'footerWidgets' => $footer->definitions(),
            'widgetData' => $header->merge($footer)->deferred(),
        ];
    }

    /**
     * The page key used for resource-level widget selection.
     */
    protected function widgetPageKey(): string
    {
        return static::$page;
    }

    /**
     * Widget filters are scoped to the exact resource page they belong to.
     *
     * A record page also includes the record key, so a filter chosen while
     * viewing one record is never restored over another record.
     *
     * @param  list<class-string<Widget>>  $header
     * @param  list<class-string<Widget>>  $footer
     */
    private function resolveWidgetFilters(?PageContext $context, array $header, array $footer): WidgetFilters
    {
        return WidgetFilters::fromRequest(
            request(),
            widgetSchemas: WidgetCollection::filterSchemas([...$header, ...$footer]),
            sessionKey: $this->widgetFilterSessionKey($context),
        );
    }

    protected function widgetFilterSessionKey(?PageContext $context = null): string
    {
        $key = sprintf(
            'panel.%s.resource.%s.page.%s',
            $this->panel()->getId(),
            static::$resource::slug(),
            $this->widgetPageKey(),
        );

        $record = $context?->record();

        if ($record !== null) {
            $key .= '.record.'.$record->getKey();
        }

        return $key;
    }

    /**
     * Serializes the form and runs the fill hooks over its values.
     *
     * The hooks work on a flat `name => value` map rather than on the
     * serialized tree, so a page shaping one field does not have to know how
     * the schema is nested.
     *
     * @return array<string, mixed>
     */
    protected function fillForm(FormSchema $schema, ?Model $record = null): array
    {
        $this->beforeFill();

        $form = $schema->toArray($record);

        $data = $this->mutateFormDataBeforeFill($this->formValues($form['schema']));

        $form['schema'] = $this->applyFormValues($form['schema'], $data);

        $this->afterFill($data);

        return $form;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    private function formValues(array $components): array
    {
        $values = [];

        foreach ($components as $component) {
            if (($component['component'] ?? null) === 'field') {
                $values[$component['name']] = $component['value'] ?? null;

                continue;
            }

            $values = [...$values, ...$this->formValues($component['schema'] ?? [])];
        }

        return $values;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function applyFormValues(array $components, array $values): array
    {
        foreach ($components as $index => $component) {
            if (($component['component'] ?? null) === 'field') {
                if (array_key_exists($component['name'], $values)) {
                    $components[$index]['value'] = $values[$component['name']];
                }

                continue;
            }

            if (isset($component['schema'])) {
                $components[$index]['schema'] = $this->applyFormValues($component['schema'], $values);
            }
        }

        return $components;
    }

    /**
     * The resource's position when it states one, the panel's otherwise.
     */
    protected function subNavigationPosition(): SubNavigationPosition
    {
        return static::$resource::subNavigationPosition()
            ?? $this->panel()->getSubNavigationPosition();
    }

    /**
     * The links between this record's pages, plus where they sit.
     *
     * Empty on a page with no record — creating one has nowhere else to be —
     * and empty when only one page is reachable.
     *
     * @return array{items: list<array<string, mixed>>, position: string}
     */
    protected function subNavigation(?Model $record, string $currentPage): array
    {
        return [
            'items' => $record === null
                ? []
                : RecordSubNavigation::for(static::$resource, $record, $currentPage),
            'position' => $this->subNavigationPosition()->value,
        ];
    }

    /**
     * @return class-string<PanelResource>
     */
    public static function resource(): string
    {
        return static::$resource;
    }

    protected function panel(): Panel
    {
        return app(PanelManager::class)->currentPanel()
            ?? throw PanelRegistrationException::noCurrentPanel();
    }

    protected function dashboardUrl(): string
    {
        return route($this->panel()->routeName('dashboard'), absolute: false);
    }

    /**
     * The trail every resource page starts with: the panel dashboard, then
     * the resource index.
     *
     * @return list<Breadcrumb>
     */
    protected function baseBreadcrumbs(): array
    {
        $resource = static::$resource;

        return [
            Breadcrumb::make(__('panda-panel::pages.dashboard.title'))->url($this->dashboardUrl()),
            ...$this->parentBreadcrumbs(),
            Breadcrumb::make($resource::pluralLabel())->url($resource::url()),
        ];
    }

    /**
     * The parent's own trail, for a nested resource.
     *
     * Empty for every other resource, which is why the trail can be built the
     * same way on every page: a resource with no parent contributes nothing
     * rather than each page having to ask whether it is nested.
     *
     * @return list<Breadcrumb>
     */
    protected function parentBreadcrumbs(): array
    {
        $parentResource = static::$resource::parentResource();
        $parent = ParentRecord::current();

        if ($parentResource === null || $parent === null) {
            return [];
        }

        $title = $parentResource::recordTitle($parent);

        $canView = array_key_exists('view', $parentResource::pages())
            && $parentResource::canView($parent);

        return [
            Breadcrumb::make($parentResource::pluralLabel())->url($parentResource::url()),
            $canView
                ? Breadcrumb::make($title)->url($parentResource::url('view', $parent))
                : Breadcrumb::make($title),
        ];
    }

    /**
     * The relation tables shown beneath a record.
     *
     * Every manager the resource declares, each authorized and each reading
     * its own slice of the query string. A manager the user may not read is
     * absent rather than empty, and never runs its query.
     *
     * A resource with a `ManageRelatedRecords` page for a relation still gets
     * that relation inline here — where the manager appears is the page's
     * decision, and a page that wants only some of them overrides this.
     *
     * @return list<array<string, mixed>>
     */
    protected function relationTables(Request $request, Model $record): array
    {
        return RelationTable::forRecord(static::$resource, $record, $request);
    }

    /**
     * The record crumb links to the view page only when there is one and the
     * user may open it; otherwise it is plain text rather than a link that
     * would 403.
     */
    protected function recordCrumb(Model $record, string $title): Breadcrumb
    {
        $resource = static::$resource;

        $canView = array_key_exists('view', $resource::pages()) && $resource::canView($record);

        return $canView
            ? Breadcrumb::make($title)->url($resource::url('view', $record))
            : Breadcrumb::make($title);
    }

    /**
     * @param  list<Breadcrumb>  $crumbs
     * @return list<array{label: string, href: string|null, current: bool}>
     */
    protected function serializeBreadcrumbs(array $crumbs): array
    {
        return array_map(
            static fn (Breadcrumb $crumb): array => $crumb->toArray(),
            $crumbs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function resourceMetadata(): array
    {
        $resource = static::$resource;

        $parent = $resource::isNested() ? ParentRecord::current() : null;

        return [
            'slug' => $resource::slug(),
            'label' => $resource::label(),
            'pluralLabel' => $resource::pluralLabel(),
            'indexUrl' => $resource::url(),
            // The action endpoints are one per panel and carry no parent
            // segment, so a nested resource's table has to send its parent
            // with every action it posts.
            'parentKey' => $parent?->getKey(),
        ];
    }

    /**
     * The record named for a human, used in breadcrumbs and headings.
     */
    protected function recordTitle(Model $record): string
    {
        $resource = static::$resource;

        return $resource::recordTitle($record);
    }

    /**
     * Where the frontend posts an action. Sent as data rather than built in
     * Vue, so no panel URL is hardcoded there.
     *
     * On every resource page rather than only the list, because a view page's
     * infolist can carry actions too — and they run through the same set.
     *
     * @return array<string, string>
     */
    protected function actionEndpoints(): array
    {
        $panel = $this->panel();

        return [
            'record' => route($panel->routeName('actions.record'), absolute: false),
            'bulk' => route($panel->routeName('actions.bulk'), absolute: false),
            'reorder' => route($panel->routeName('actions.reorder'), absolute: false),
            'cell' => route($panel->routeName('actions.cell'), absolute: false),
            'table' => route($panel->routeName('actions.table'), absolute: false),
            'form' => route($panel->routeName('actions.form'), absolute: false),
            'infolist' => route($panel->routeName('actions.infolist'), absolute: false),
        ];
    }

    /**
     * The sub-navigation of the cluster this resource belongs to.
     *
     * Null when it belongs to none, and null too when the cluster has nothing
     * this user may see — so a bar with no links in it is never rendered.
     *
     * @return array<string, mixed>|null
     */
    protected function clusterNavigation(): ?array
    {
        $cluster = static::$resource::cluster();

        return $cluster === null
            ? null
            : ClusterNavigation::for($this->panel(), $cluster, request()->path());
    }
}
