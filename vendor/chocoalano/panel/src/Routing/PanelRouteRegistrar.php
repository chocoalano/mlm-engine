<?php

declare(strict_types=1);

namespace PandaPanel\Routing;

use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Contracts\Routing\Registrar;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Http\Controllers\PanelActionController;
use PandaPanel\Http\Controllers\PanelActionFormController;
use PandaPanel\Http\Controllers\PanelAuthController;
use PandaPanel\Http\Controllers\PanelDashboardController;
use PandaPanel\Http\Controllers\PanelExportController;
use PandaPanel\Http\Controllers\PanelFormOptionsController;
use PandaPanel\Http\Controllers\PanelFormStateController;
use PandaPanel\Http\Controllers\PanelImportController;
use PandaPanel\Http\Controllers\PanelIntegrationController;
use PandaPanel\Http\Controllers\PanelLocaleController;
use PandaPanel\Http\Controllers\PanelNotificationController;
use PandaPanel\Http\Controllers\PanelPageController;
use PandaPanel\Http\Controllers\PanelRelationController;
use PandaPanel\Http\Controllers\PanelSearchController;
use PandaPanel\Http\Controllers\PanelTwoFactorController;
use PandaPanel\Http\Controllers\PanelUploadController;
use PandaPanel\Http\Middleware\RequireEmailCode;
use PandaPanel\Http\Middleware\RequireTwoFactor;
use PandaPanel\Http\Middleware\ResolvePanel;
use PandaPanel\Http\Middleware\ResolveParentRecord;
use PandaPanel\Http\Middleware\ResolveTenant;
use PandaPanel\Http\Middleware\SetPanelLocale;
use PandaPanel\Pages\Page;
use PandaPanel\Resources\Pages\ResourcePage;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\ParentRecord;

/**
 * Registers one route group per panel.
 *
 * Route names follow `panel.{panel_id}.*` so Wayfinder output and
 * server-side URL generation stay predictable.
 *
 * `ResolvePanel` is appended last with the panel id as a parameter. Passing
 * the id explicitly means panel resolution never depends on path matching,
 * which keeps two panels sharing a prefix unambiguous.
 *
 * Every route points at a controller method, never a closure, so
 * `php artisan route:cache` keeps working.
 */
final class PanelRouteRegistrar
{
    /**
     * Page name to the routes it registers, in registration order.
     *
     * Each entry is `[verb, path, controller method, route name]`. Static
     * segments come before the `{record}` wildcard, otherwise `/create` is
     * matched as a record key.
     *
     * A write verb gets its own route name (`store`, `update`) because
     * Laravel requires names to be unique; those two extend the naming
     * convention with the usual Laravel verbs.
     *
     * @var array<string, list<array{0: string, 1: string, 2: string, 3: string}>>
     */
    private const PAGE_ROUTES = [
        'index' => [
            ['get', '/', 'render', 'index'],
        ],
        'create' => [
            ['get', 'create', 'render', 'create'],
            ['post', 'create', 'handle', 'store'],
            ['post', 'create/step', 'validateStep', 'validateCreateStep'],
        ],
        'view' => [
            ['get', '{record}', 'render', 'view'],
        ],
        'edit' => [
            ['get', '{record}/edit', 'render', 'edit'],
            ['put', '{record}/edit', 'handle', 'update'],
            ['post', '{record}/edit/step', 'validateStep', 'validateEditStep'],
        ],
    ];

    public function __construct(
        private readonly Registrar $router,
        private readonly PanelManager $manager,
    ) {}

    /**
     * Path shape to the resource that claimed it, per panel.
     *
     * Laravel matches the first route for a path and silently ignores the
     * rest, so two resources claiming one shape means one of them is simply
     * unreachable — a `ManageRelatedRecords` page at
     * `projects/{record}/tasks` and a nested resource at
     * `projects/{parentRecord}/tasks` are the same path as far as matching is
     * concerned. Comparing normalized shapes catches that at boot instead of
     * leaving it to be discovered as a page that renders the wrong thing.
     *
     * @var array<string, class-string<PanelResource>>
     */
    private array $claimedPaths = [];

    public function registerAll(): void
    {
        foreach ($this->manager->all() as $panel) {
            $this->register($panel);
        }
    }

    public function register(Panel $panel): void
    {
        $attributes = [
            'prefix' => $panel->getPath(),
            'as' => $panel->getRouteNamePrefix(),
            'middleware' => [
                ...$panel->getMiddleware(),
                ResolvePanel::class.':'.$panel->getId(),
                // Before the guards and before every controller: a schema is
                // built with its labels already resolved, so a controller
                // that ran first would have built it in the wrong language.
                SetPanelLocale::class.':'.$panel->getId(),
                // After the panel is resolved, so it can be asked whether it
                // demands one. A no-op for a panel that does not.
                RequireTwoFactor::class.':'.$panel->getId(),
                // And, for an account that turned emailed codes on, until
                // this session has answered one.
                RequireEmailCode::class.':'.$panel->getId(),
                // Last, so the tenant is bound after the user is known and
                // before any controller can query. A panel without tenancy
                // never gets it — see `Panel::tenant()`.
                ...($panel->hasTenancy() ? [ResolveTenant::class.':'.$panel->getId()] : []),
            ],
        ];

        if ($panel->getDomain() !== null) {
            $attributes['domain'] = $panel->getDomain();
        }

        // Paths are only ambiguous within one panel: two panels sit under
        // different prefixes and cannot shadow each other.
        $this->claimedPaths = [];

        // The panel's own front door, registered *outside* its middleware:
        // these are the pages a guest sees, and putting them behind `auth`
        // would send somebody who cannot sign in to the page that tells them
        // to sign in.
        $this->registerAuth($panel, $attributes);

        $this->router->group($attributes, function () use ($panel): void {
            $this->router
                ->get('/', PanelDashboardController::class)
                ->name('dashboard');

            $this->router
                ->get('search', PanelSearchController::class)
                ->name('search');

            // A searchable select asks here for the rows its bounded first
            // page could not show. The field is resolved out of the schema
            // that declared it, so nothing about a column or a table ever
            // comes from the request.
            // Both verbs, one handler. GET is what a select that depends on
            // nothing needs; POST is what one that depends on a sibling needs,
            // because the form's values go in the body — there is no bound on
            // how much a form holds and a query string has one.
            $this->router
                ->match(['get', 'post'], 'options', PanelFormOptionsController::class)
                ->name('options');

            // A file is stored before the form is submitted, so the submit
            // stays an ordinary request carrying a path. Where the file may
            // land comes from the field's declaration, never the request.
            $this->router
                ->post('uploads', PanelUploadController::class)
                ->name('uploads');

            // A live field asks here what the form should look like now. Not
            // a submit: nothing is validated and nothing is written.
            $this->router
                ->post('form-state', PanelFormStateController::class)
                ->name('form-state');

            // A finished export is fetched here. The directory is built from
            // whoever is asking, so the only files reachable are that user's
            // own — see `PanelExportController`.
            // Named `export-file` rather than `exports`: the route name
            // becomes an identifier in the generated Wayfinder module, and
            // `exports` is not a name a TypeScript module can bind.
            $this->router
                ->get('exports/{file}', PanelExportController::class)
                ->name('export-file');

            // The rows an import could not accept, filed the same way and
            // reachable only by the user whose import produced them.
            $this->router
                ->get('imports/{file}', PanelImportController::class)
                ->name('import-file');

            // Changing the language, for somebody signed in.
            $this->router
                ->post('locale', PanelLocaleController::class)
                ->name('locale');

            // The notification centre. Every route is scoped to the
            // authenticated user's own rows, so the scope is the
            // authorization — see `PanelNotificationController`.
            $this->router->group(['prefix' => 'notifications', 'as' => 'notifications.'], function (): void {
                $this->router
                    ->get('/', [PanelNotificationController::class, 'index'])
                    ->name('index');

                $this->router
                    ->post('read', [PanelNotificationController::class, 'read'])
                    ->name('read');

                $this->router
                    ->post('clear', [PanelNotificationController::class, 'clear'])
                    ->name('clear');
            });

            // Answering the emailed-code challenge. Inside the panel's own
            // middleware — these are for somebody already signed in — but
            // exempt from the code check itself, or answering it would be
            // refused by the thing being answered.
            $this->router->group(['prefix' => 'two-factor', 'as' => 'auth.two-factor.'], function (): void {
                $this->router
                    ->get('challenge', [PanelTwoFactorController::class, 'challenge'])
                    ->name('challenge');

                $this->router
                    ->post('send', [PanelTwoFactorController::class, 'send'])
                    ->name('send');

                $this->router
                    ->post('verify', [PanelTwoFactorController::class, 'verify'])
                    ->name('verify');

                // Turning it on or off is a change to the account, so it is
                // held to the same bar the rest of the security page is.
                $this->router
                    ->post('enable', [PanelTwoFactorController::class, 'enable'])
                    ->middleware(RequirePassword::class)
                    ->name('enable');

                $this->router
                    ->post('disable', [PanelTwoFactorController::class, 'disable'])
                    ->middleware(RequirePassword::class)
                    ->name('disable');
            });

            $this->registerActions();
            $this->registerRelations();
            $this->registerPages($panel);
            $this->registerResources($panel);
        });
    }

    /**
     * The panel's login, registration, password reset, and verification
     * pages.
     *
     * Guest routes, so they carry the panel's prefix and name but not its
     * middleware. Only the pages: the forms post to Fortify's own endpoints,
     * because duplicating the login POST per panel would mean duplicating
     * rate limiting, two-factor, passkeys, and session handling.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function registerAuth(Panel $panel, array $attributes): void
    {
        if (! $panel->hasLogin()) {
            return;
        }

        // The panel's *base* middleware, without its auth stack: these pages
        // still need a session, a CSRF token, and the Inertia middleware —
        // dropping those would leave a login form that cannot be submitted.
        // What is deliberately absent is `auth`, because sending somebody who
        // cannot sign in to the page that tells them to sign in is a loop.
        $guest = [
            ...$attributes,
            'middleware' => [
                ...$panel->getBaseMiddleware(),
                ResolvePanel::class.':'.$panel->getId(),
                // A login screen is exactly where somebody notices they are
                // reading the wrong language, and the switcher on it has to
                // work before there is an account to remember the choice on.
                SetPanelLocale::class.':'.$panel->getId(),
            ],
        ];

        $this->router->group($guest, function () use ($panel): void {
            // The same controller, reachable before signing in. A reader who
            // cannot read the login screen cannot sign in to reach the other
            // one, and the choice is in the session either way — so it
            // survives the sign-in that follows.
            $this->router
                ->post('locale', PanelLocaleController::class)
                ->name('auth.locale');

            $this->router
                ->get('login', [PanelAuthController::class, 'login'])
                ->name('auth.login');

            if ($panel->hasRegistration()) {
                $this->router
                    ->get('register', [PanelAuthController::class, 'register'])
                    ->name('auth.register');
            }

            if ($panel->hasPasswordReset()) {
                $this->router
                    ->get('forgot-password', [PanelAuthController::class, 'requestPasswordReset'])
                    ->name('auth.password.request');

                $this->router
                    ->get('reset-password/{token}', [PanelAuthController::class, 'resetPassword'])
                    ->name('auth.password.reset');
            }

            if ($panel->hasEmailVerification()) {
                $this->router
                    ->get('verify-email', [PanelAuthController::class, 'verifyEmail'])
                    ->name('auth.verification.notice');
            }
        });
    }

    /**
     * One action endpoint per panel rather than per resource. The resource is
     * part of the payload and is resolved against this panel's registry, so
     * a resource from another panel cannot be addressed here.
     */
    private function registerActions(): void
    {
        $this->router->group(['prefix' => 'actions', 'as' => 'actions.'], function (): void {
            $this->router
                ->post('record', [PanelActionController::class, 'record'])
                ->name('record');

            $this->router
                ->post('bulk', [PanelActionController::class, 'bulk'])
                ->name('bulk');

            $this->router
                ->post('reorder', [PanelActionController::class, 'reorder'])
                ->name('reorder');

            // An editable cell writes here. It is an action in every sense
            // that matters — it names a record, authorizes it, and changes
            // it — so it lives with the others rather than inventing a
            // second write surface.
            $this->router
                ->post('cell', [PanelActionController::class, 'cell'])
                ->name('cell');

            $this->router
                ->post('table', [PanelActionController::class, 'table'])
                ->name('table');

            // A view page's actions are a different whitelist from a table's,
            // so they are resolved through a different endpoint rather than
            // one lookup that would let either page run the other's.
            $this->router
                ->post('infolist', [PanelActionController::class, 'infolist'])
                ->name('infolist');

            // An action that carries a form fetches it when its dialog opens
            // and submits it back here. Two requests, so a list of twenty
            // records ships twenty buttons rather than twenty forms.
            $this->router
                ->get('form', [PanelActionFormController::class, 'show'])
                ->name('form');

            $this->router
                ->post('form', [PanelActionFormController::class, 'submit'])
                ->name('submit');
        });
    }

    /**
     * One relation endpoint set per panel, for the same reason actions have
     * one: the resource and the relation are part of the request and are
     * resolved against this panel's registry, so a relation belonging to
     * another panel's resource cannot be addressed here.
     *
     * The form and save routes take their context from the query string,
     * which is why they need no path parameters — see
     * `PanelRelationController`.
     */
    private function registerRelations(): void
    {
        $this->router->group(['prefix' => 'relations', 'as' => 'relations.'], function (): void {
            $this->router
                ->get('form', [PanelRelationController::class, 'form'])
                ->name('form');

            $this->router
                ->post('form', [PanelRelationController::class, 'save'])
                ->name('save');

            $this->router
                ->post('action', [PanelRelationController::class, 'action'])
                ->name('action');

            // An action a relation's table declared, when it carries a form.
            // Its own route rather than the resource action-form endpoint,
            // which resolves actions out of the resource's table and so could
            // never find one of these — that mismatch is what made every
            // relation action with a form answer 404.
            //
            // The context is in the query string on both verbs, which is what
            // lets the POST body be nothing but the user's values.
            //
            // Named `action-form-schema` rather than `action-form`, which the
            // URI still is. Wayfinder names a helper after the last segment of
            // the route name and, with `formVariants` on, declares a second
            // one with `Form` appended — so `action` already owns `actionForm`
            // in this module, and a route named `action-form` would redeclare
            // it. Wayfinder has no per-route filter or rename, so the only
            // place the collision can be resolved is here. See
            // `WayfinderRouteNamingTest`.
            $this->router
                ->get('action-form', [PanelRelationController::class, 'actionForm'])
                ->name('action-form-schema');

            $this->router
                ->post('action-form', [PanelRelationController::class, 'submitAction'])
                ->name('submit-action');

            $this->router
                ->post('bulk', [PanelRelationController::class, 'bulk'])
                ->name('bulk');
        });
    }

    /**
     * Standalone pages.
     *
     * The page class is bound into the route defaults rather than taken from
     * the URL, so the controller never resolves a class name from a request.
     * Page slugs are already validated against resource slugs at
     * registration, so the two cannot shadow each other.
     */
    private function registerPages(Panel $panel): void
    {
        // A panel's extra dashboards are pages in every sense but discovery:
        // they are named on the panel rather than found in a directory, so
        // they are registered here alongside the ones that were.
        //
        // Deduplicated by class, because a dashboard that also lives under a
        // discovered path arrives twice and is still one page.
        $pages = array_values(array_unique([
            ...$panel->getExtraDashboards(),
            ...$this->manager->pages($panel)->all(),
        ]));

        foreach ($pages as $page) {
            /** @var class-string<Page> $page */
            $route = $this->router
                ->get($page::routePath(), PanelPageController::class)
                ->defaults('page', $page)
                ->name('pages.'.$page::slug());

            if ($page::middleware() !== []) {
                $route->middleware($page::middleware());
            }
        }
    }

    /**
     * Resource pages are registered from `Resource::pages()`, keyed by page
     * name, so the route name and the page class stay in one place.
     */
    private function registerResources(Panel $panel): void
    {
        $registry = $this->manager->resources($panel);

        foreach ($registry->all() as $resource) {
            /** @var class-string<PanelResource> $resource */
            // The panel owns the slug: the same class may be `users` here and
            // `people` in another panel, and there is no current panel during
            // boot to ask the class itself.
            $slug = $registry->slugFor($resource);

            // A cluster prefixes the path and nothing else. The route name
            // stays `resources.{slug}.`, so every `Resource::url()` in the
            // application keeps working and only the URL it produces moves.
            $cluster = $resource::cluster();

            $attributes = [
                'prefix' => $cluster === null ? $slug : $cluster::slug().'/'.$slug,
                'as' => "resources.{$slug}.",
            ];

            // A nested resource lives under its parent's record, and the
            // middleware that binds that record is attached here rather than
            // left to the pages: every route in the group needs the scope,
            // and a page that forgot it would query unscoped.
            $parent = $resource::parentResource();

            if ($parent !== null) {
                // A parent from another panel would produce a path built from
                // its default slug, pointing at a route that does not exist
                // here. Better to refuse at boot than to ship dead links.
                if (! $registry->contains($parent)) {
                    throw PanelRegistrationException::unregisteredParentResource($resource, $parent);
                }

                $parentSlug = $registry->slugFor($parent);

                $attributes['prefix'] = sprintf(
                    '%s/{%s}/%s',
                    $parentSlug,
                    ParentRecord::routeParameter(),
                    $slug,
                );

                $attributes['middleware'] = [ResolveParentRecord::class.':'.$resource];
            }

            $prefix = $attributes['prefix'];

            $this->router->group($attributes, function () use ($resource, $prefix, $slug): void {
                $singular = $resource::isSingular();

                $this->registerIntegrationRoutes($resource, $prefix, $slug);

                foreach ($this->orderPages($resource::pages()) as $name => $page) {
                    foreach ($this->routesFor($name, $page, $singular) as $route) {
                        [$verb, $path, $method, $routeName] = $route;

                        $this->claimPath($verb, $prefix, $path, $resource);

                        $this->router
                            ->{$verb}($path, [$page, $method])
                            ->name($routeName);
                    }
                }
            });
        }
    }

    /**
     * The integrations screen, for a resource that asked for one.
     *
     * Registered here rather than declared in `pages()` because it is not the
     * application's page: it belongs to the framework, it is the same for
     * every resource, and a resource should not have to list it to get it. A
     * resource that never called `isEnabled(true)` registers nothing, so the
     * URL 404s rather than answering 403 — there is no screen to be refused.
     *
     * `{integration}` is a plain id and is looked up scoped to this panel and
     * this resource, so an id from another screen cannot be edited here.
     *
     * @param  class-string<PanelResource>  $resource
     */
    private function registerIntegrationRoutes(string $resource, string $prefix, string $slug): void
    {
        if (! $resource::integrationSettings()->enabled()) {
            return;
        }

        $name = "resources.{$slug}.integrations";
        $controller = PanelIntegrationController::class;

        $routes = [
            ['get', 'integrations', 'index', ''],
            ['post', 'integrations', 'store', '.store'],
            ['put', 'integrations/{integration}', 'update', '.update'],
            ['delete', 'integrations/{integration}', 'destroy', '.destroy'],
            ['post', 'integrations/{integration}/send', 'send', '.send'],
            ['post', 'integrations/{integration}/rotate', 'rotate', '.rotate'],
        ];

        foreach ($routes as [$verb, $path, $method, $suffix]) {
            $this->claimPath($verb, $prefix, $path, $resource);

            $this->router
                ->{$verb}($path, [$controller, $method])
                // The slug travels as a default rather than as a segment: the
                // path is already inside the resource's prefix, and a second
                // copy of the slug in the URL would be a second thing that
                // could disagree with the first.
                ->defaults('resource', $slug)
                ->name($name.$suffix);
        }
    }

    /**
     * Records that one resource owns a path shape, and refuses a second
     * claim on it.
     *
     * Parameter *names* are erased before comparing: `{record}` and
     * `{parentRecord}` are different names for the same wildcard segment, and
     * the router does not distinguish them either.
     *
     * @param  class-string<PanelResource>  $resource
     *
     * @throws PanelRegistrationException
     */
    private function claimPath(string $verb, string $prefix, string $path, string $resource): void
    {
        $full = trim($prefix.'/'.$path, '/');
        $shape = $verb.' '.preg_replace('/\{[^}]+\}/', '{}', $full);

        $existing = $this->claimedPaths[$shape] ?? null;

        if ($existing !== null && $existing !== $resource) {
            throw PanelRegistrationException::collidingRoutePath($full, $existing, $resource);
        }

        $this->claimedPaths[$shape] = $resource;
    }

    /**
     * The routes one resource page registers.
     *
     * The four standard keys have fixed shapes. Anything else is a custom
     * page: one GET at the path it declares, which defaults to the page key
     * and may contain `{record}` for a page that operates on one.
     *
     * @param  class-string  $page
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function routesFor(string $name, string $page, bool $singular): array
    {
        if (array_key_exists($name, self::PAGE_ROUTES)) {
            return $singular
                ? $this->withoutRecordSegment(self::PAGE_ROUTES[$name])
                : self::PAGE_ROUTES[$name];
        }

        $path = is_subclass_of($page, ResourcePage::class) ? $page::routePath($name) : $name;

        return [['get', $singular ? $this->stripRecord($path) : $path, 'render', $name]];
    }

    /**
     * A singular resource has nothing to choose between, so its pages sit
     * directly under the resource: `/settings` and `/settings/edit` rather
     * than `/settings/{record}`.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $routes
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function withoutRecordSegment(array $routes): array
    {
        return array_map(
            fn (array $route): array => [$route[0], $this->stripRecord($route[1]), $route[2], $route[3]],
            $routes,
        );
    }

    private function stripRecord(string $path): string
    {
        $stripped = trim(str_replace('{record}', '', $path), '/');

        return $stripped === '' ? '/' : $stripped;
    }

    /**
     * @param  array<string, class-string>  $pages
     * @return array<string, class-string>
     */
    private function orderPages(array $pages): array
    {
        uksort($pages, static function (string $a, string $b): int {
            $order = array_keys(self::PAGE_ROUTES);

            $rank = static fn (string $key): int => ($index = array_search($key, $order, true)) === false
                ? count($order)
                : $index;

            return $rank($a) <=> $rank($b);
        });

        return $pages;
    }
}
