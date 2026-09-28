<?php

declare(strict_types=1);

namespace PandaPanel\Pages;

use BackedEnum;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use PandaPanel\Clusters\Cluster;
use PandaPanel\Contracts\PageContract;
use PandaPanel\Contracts\PanelContract;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Support\Breadcrumb;
use PandaPanel\Support\ClusterNavigation;
use PandaPanel\Support\Label;
use PandaPanel\Support\NavigationGroupName;
use PandaPanel\Support\NavigationItem;
use PandaPanel\Widgets\Support\WidgetFilters;
use PandaPanel\Widgets\Widget;

/**
 * A standalone panel page: not a resource, no records, no table.
 *
 * `canAccess()` is enforced by the route, not only by navigation. A page
 * hidden from the sidebar but reachable by URL would be no protection at
 * all.
 *
 * A page may host widgets. Their authorization and lazy loading work exactly
 * as they do on a dashboard, because the dashboard is itself a page.
 */
abstract class Page implements PageContract
{
    protected static ?string $title = null;

    protected static ?string $heading = null;

    protected static ?string $subheading = null;

    protected static ?string $slug = null;

    /**
     * The Inertia component. Defaults to the generic renderer so a page with
     * nothing special to draw does not need a Vue file at all.
     */
    protected static string $component = 'panel/Page';

    protected static ?string $navigationLabel = null;

    protected static ?string $navigationIcon = null;

    protected static string|BackedEnum|null $navigationGroup = null;

    protected static ?string $activeNavigationIcon = null;

    /**
     * The cluster this page belongs to. See `Resource::$cluster`.
     *
     * @var class-string<Cluster>|null
     */
    protected static ?string $cluster = null;

    protected static int $navigationSort = 0;

    protected static bool $shouldRegisterNavigation = true;

    /**
     * Middleware appended to this page's route, on top of the panel's stack.
     *
     * For concerns the route must enforce before the page is ever
     * constructed — password confirmation, a signed URL — which
     * `canAccess()` cannot express because it answers yes or no rather than
     * redirecting.
     *
     * @var list<string>
     */
    protected static array $middleware = [];

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return static::$middleware;
    }

    public static function slug(): string
    {
        return static::$slug ?? Str::kebab(class_basename(static::class));
    }

    /**
     * Where this page is routed, under its cluster's prefix when it has one.
     *
     * The route *name* is untouched by the prefix, so every `Page::url()` in
     * the application keeps working and only the path it produces changes.
     */
    public static function routePath(): string
    {
        $cluster = static::$cluster;

        return $cluster === null
            ? static::slug()
            : $cluster::slug().'/'.static::slug();
    }

    /**
     * @return class-string<Cluster>|null
     */
    public static function cluster(): ?string
    {
        return static::$cluster;
    }

    public static function activeNavigationIcon(): ?string
    {
        return static::$activeNavigationIcon ?? static::$navigationIcon;
    }

    public static function title(): string
    {
        return static::$title ?? Label::resolve(
            'pages',
            class_basename(static::class),
            static fn (): string => Str::headline(class_basename(static::class)),
        );
    }

    public static function heading(): string
    {
        return static::$heading ?? static::title();
    }

    /**
     * The line under the heading.
     *
     * A method rather than a direct read of the property, so a page whose
     * subheading is translated has somewhere to put `__()` — a static
     * property is initialized before the translator can answer. Every page
     * that assigns the property still works: this is what reads it.
     */
    public static function subheading(): ?string
    {
        return static::$subheading;
    }

    /**
     * Which navigation group this page sits in, for the same reason.
     */
    public static function navigationGroup(): string|BackedEnum|null
    {
        return static::$navigationGroup;
    }

    public static function canAccess(): bool
    {
        return true;
    }

    public static function navigationItem(PanelContract $panel): ?NavigationItem
    {
        if (! static::$shouldRegisterNavigation) {
            return null;
        }

        return NavigationItem::make(
            label: static::$navigationLabel ?? static::title(),
            href: static::url($panel instanceof Panel ? $panel : null),
            icon: static::$navigationIcon,
            activeIcon: static::activeNavigationIcon(),
            sort: static::$navigationSort,
            group: static::navigationGroup(),
        );
    }

    public static function routeName(Panel|string|null $panel = null): string
    {
        return static::resolvePanel($panel)->routeName('pages.'.static::slug());
    }

    public static function url(Panel|string|null $panel = null): string
    {
        return route(static::routeName($panel), absolute: false);
    }

    /**
     * Page-specific props. Serializable values only.
     *
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return [];
    }

    /**
     * Widget classes shown on this page, in the order they should appear.
     *
     * @return list<class-string<Widget>>
     */
    public function widgets(): array
    {
        return [];
    }

    /**
     * @return list<Breadcrumb>
     */
    public function breadcrumbs(): array
    {
        $crumbs = [
            Breadcrumb::make(__('panda-panel::pages.dashboard.title'))->url($this->dashboardUrl()),
        ];

        $group = NavigationGroupName::resolve(static::navigationGroup());

        if ($group !== null) {
            $crumbs[] = Breadcrumb::make($group);
        }

        $crumbs[] = Breadcrumb::make(static::title())->current();

        return $crumbs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function headerActions(): array
    {
        return [];
    }

    public function render(): Response
    {
        abort_unless(static::canAccess(), 403);

        $filters = $this->resolveFilters();
        $widgets = $this->resolveWidgets($filters);
        $schema = $this->filterSchema();

        return Inertia::render(static::$component, [
            'page' => $this->metadata(),
            'widgets' => $widgets->definitions(),
            'widgetData' => $widgets->deferred(),
            // Null for a page that declares no filters, so the frontend
            // renders nothing rather than an empty bar.
            'filters' => $schema === null ? null : [
                'form' => $schema->toArrayWithState(null, $filters->dashboard()),
            ],
            ...$this->props(),
        ]);
    }

    /**
     * A form every widget on this page is filtered by.
     *
     * Null by default. A dashboard that declares one filters all of its
     * widgets at once — "this quarter" is a question about the page, not
     * about one figure on it — and a widget can still carry a filter of its
     * own on top.
     */
    public function filterSchema(): ?FormSchema
    {
        return null;
    }

    /**
     * Where this page's filter state is remembered between visits.
     *
     * Per page rather than per panel: two dashboards filtered differently are
     * two different questions, and restoring one over the other would answer
     * neither.
     */
    protected function filterSessionKey(): string
    {
        return 'panel.'.$this->panel()->getId().'.page.'.static::slug();
    }

    protected function resolveFilters(): WidgetFilters
    {
        return WidgetFilters::fromRequest(
            request(),
            $this->filterSchema(),
            WidgetCollection::filterSchemas($this->widgets()),
            $this->filterSessionKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadata(): array
    {
        return [
            'title' => static::title(),
            'heading' => static::heading(),
            'subheading' => static::subheading(),
            'breadcrumbs' => array_map(
                static fn (Breadcrumb $crumb): array => $crumb->toArray(),
                $this->breadcrumbs(),
            ),
            'headerActions' => $this->headerActions(),
            'scope' => static::renderHookScope(),
            // Null unless this page is in a cluster, and null too when the
            // cluster has nothing this user may see — so a bar with no links
            // in it is never rendered.
            'cluster' => static::$cluster === null
                ? null
                : ClusterNavigation::for($this->panel(), static::$cluster, request()->path()),
        ];
    }

    /**
     * What a render hook's scope is matched against. A slug, never a class
     * name: nothing in page metadata may name a PHP class.
     */
    public static function renderHookScope(): string
    {
        return 'page:'.static::slug();
    }

    protected function resolveWidgets(?WidgetFilters $filters = null): WidgetCollection
    {
        return WidgetCollection::for($this->widgets(), null, $filters);
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

    protected static function resolvePanel(Panel|string|null $panel): Panel
    {
        if ($panel instanceof Panel) {
            return $panel;
        }

        $manager = app(PanelManager::class);

        if (is_string($panel)) {
            return $manager->get($panel);
        }

        return $manager->currentPanel() ?? throw PanelRegistrationException::noCurrentPanel();
    }
}
