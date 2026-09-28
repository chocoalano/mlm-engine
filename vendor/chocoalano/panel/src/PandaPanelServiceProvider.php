<?php

declare(strict_types=1);

namespace PandaPanel;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use PandaPanel\Cache\PanelManifest;
use PandaPanel\Console\Commands\CachePanelsCommand;
use PandaPanel\Console\Commands\ClearPanelsCommand;
use PandaPanel\Console\Commands\InstallPanelCommand;
use PandaPanel\Console\Commands\MakePanelCommand;
use PandaPanel\Console\Commands\MakePanelPageCommand;
use PandaPanel\Console\Commands\MakePanelRelationManagerCommand;
use PandaPanel\Console\Commands\MakePanelResourceCommand;
use PandaPanel\Console\Commands\MakePanelUserCommand;
use PandaPanel\Console\Commands\MakePanelWidgetCommand;
use PandaPanel\Console\Commands\PanelAssetsCommand;
use PandaPanel\Console\Commands\PanelPluginsCommand;
use PandaPanel\Console\Commands\PublishPanelAssetsCommand;
use PandaPanel\Console\Commands\SyncPanelIconsCommand;
use PandaPanel\Core\PanelManager;
use PandaPanel\Core\PanelProvider;
use PandaPanel\Core\PanelRegistry;
use PandaPanel\Discovery\PanelDiscoverer;
use PandaPanel\Http\Middleware\RedirectPanelHome;
use PandaPanel\Http\Middleware\RequireEmailCode;
use PandaPanel\Http\Middleware\RequireTwoFactor;
use PandaPanel\Http\Middleware\ResetPanelContext;
use PandaPanel\Http\Middleware\ResolvePanel;
use PandaPanel\Http\Middleware\ResolveParentRecord;
use PandaPanel\Http\Middleware\SetPanelLocale;
use PandaPanel\Http\Middleware\ShareFlashToast;
use PandaPanel\Http\Middleware\SharePanelData;
use PandaPanel\Http\Responses\PanelLoginResponse;
use PandaPanel\Http\Responses\PanelRegisterResponse;
use PandaPanel\Http\Responses\PanelTwoFactorLoginResponse;
use PandaPanel\Integrations\IntegrationObserver;
use PandaPanel\Resources\Resource;
use PandaPanel\Routing\PanelRouteRegistrar;
use PandaPanel\Support\Installer\PublishedAssets;
use PandaPanel\Support\NavigationBuilder;
use PandaPanel\Support\PanelContext;
use PandaPanel\Support\PanelLoginRedirect;
use PandaPanel\Support\PanelPostLogin;
use PandaPanel\Translation\PanelTranslationLoader;

/**
 * Wires the panel framework into the application.
 *
 * Panels are listed in `config/panda-panel.php` rather than discovered.
 * Registration order is visible in one place, and adding a panel is a
 * deliberate edit rather than a filesystem side effect. The classes *inside*
 * a panel are discovered; the panels themselves are not.
 */
final class PandaPanelServiceProvider extends ServiceProvider
{
    /**
     * The middleware the whole `web` group needs, in the order they must run.
     *
     * `ResetPanelContext` clears the resolved panel at the start of a request
     * so nothing leaks between them; `ShareFlashToast` maps Laravel's flash
     * keys onto the single toast channel the frontend listens on. Neither
     * belongs to a panel route group: the first has to run for requests that
     * never reach a panel, and the second for redirects back out of one.
     *
     * `RedirectPanelHome` sends a signed-in user who lands on the starter
     * kit's `/dashboard` into the panel instead. It runs before the other two
     * because a request it answers never reaches a panel screen, so there is
     * nothing to share props for.
     *
     * `SharePanelData` shares the props the panel's components are built from.
     * It belongs to the package rather than to the application's own
     * `HandleInertiaRequests`, because a prop added in a new version would
     * otherwise break every application that did not copy it across.
     *
     * @var list<class-string>
     */
    private const WEB_MIDDLEWARE = [
        ResetPanelContext::class,
        RedirectPanelHome::class,
        ShareFlashToast::class,
        SharePanelData::class,
    ];

    /**
     * The translation namespace the package's own strings live under.
     *
     * Every `__('panda-panel::…')` in `src/` resolves through it, and
     * `PanelTranslationLoader` uses it to decide which lookups an
     * application's `lang/{locale}` files are allowed to override.
     */
    public const TRANSLATION_NAMESPACE = 'panda-panel';

    /**
     * Aliases for the middleware a panel route group applies.
     *
     * The registrar names these classes directly, so the aliases exist for
     * applications that want to reference them in their own route
     * definitions — never as the framework's own way of reaching them.
     *
     * @var array<string, class-string>
     */
    private const MIDDLEWARE_ALIASES = [
        'panel' => ResolvePanel::class,
        'panel.locale' => SetPanelLocale::class,
        'panel.two-factor' => RequireTwoFactor::class,
        'panel.email-code' => RequireEmailCode::class,
        'panel.parent' => ResolveParentRecord::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->packagePath('config/panda-panel.php'), 'panda-panel');

        $this->registerTranslationLoader();
        $this->registerLoginResponses();

        $this->app->singleton(PanelRegistry::class);
        $this->app->scoped(PanelContext::class);
        $this->app->singleton(PanelDiscoverer::class);
        $this->app->singleton(PanelManifest::class);
        $this->app->singleton(PanelManager::class);
        $this->app->singleton(NavigationBuilder::class);

        $this->app->singleton(
            PanelRouteRegistrar::class,
            fn ($app): PanelRouteRegistrar => new PanelRouteRegistrar(
                $app->make(Registrar::class),
                $app->make(PanelManager::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->registerTranslations();
        $this->registerPanels();
        $this->registerMiddleware();
        $this->registerGuestRedirect();
        $this->registerRoutes();
        $this->registerIntegrations();
        $this->registerMigrations();
        $this->registerPublishing();
        $this->registerCommands();
    }

    /**
     * Sends a guest who opens a panel URL to that panel's own login.
     *
     * This used to be a step an application had to write into
     * `bootstrap/app.php` by hand, because the framework registers its own
     * default — `fn () => route('login')` — inside `withMiddleware()`'s
     * `afterResolving(Kernel::class)` hook, and anything a provider set
     * earlier was overwritten. The fix is the same one `pushWebMiddleware()`
     * uses: register a *later* hook, and call it directly when the kernel has
     * already resolved.
     *
     * Doing it here is safe because `PanelLoginRedirect` is a strict superset
     * of the framework's default — a request that is not a panel request, or
     * a panel with no login of its own, still gets `route('login')`. The one
     * thing it would override is an application that set its own custom
     * redirect, which is what the config flag is for.
     */
    private function registerGuestRedirect(): void
    {
        if ($this->app->make('config')->get('panda-panel.register_guest_redirect') !== true) {
            return;
        }

        $redirect = static function (): void {
            Authenticate::redirectUsing(PanelLoginRedirect::for(...));
            AuthenticationException::redirectUsing(PanelLoginRedirect::for(...));
        };

        if ($this->app->resolved(Kernel::class)) {
            $redirect();
        }

        $this->app->afterResolving(Kernel::class, $redirect);
    }

    /**
     * Sends somebody who has just signed in into the panel they signed in at.
     *
     * The counterpart to `registerGuestRedirect()`, and the half that was
     * missing. A panel's login page posts to Fortify's own endpoint on
     * purpose — one implementation of rate limiting, two-factor, passkeys and
     * session fixation — and Fortify then answers with a redirect to
     * `fortify.home`. That is the starter kit's `/dashboard`, or nothing at
     * all in a blank application, so signing in at `/admin/login` did not land
     * in `/admin`.
     *
     * Bound in `register()` because Fortify's own provider binds these in its
     * `register()` too, and the last binding wins. Guarded by a config flag
     * for an application that has bound its own.
     *
     * @see PanelPostLogin
     */
    private function registerLoginResponses(): void
    {
        if ($this->app->make('config')->get('panda-panel.login_redirect') !== true) {
            return;
        }

        $this->app->singleton(LoginResponseContract::class, PanelLoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, PanelTwoFactorLoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, PanelRegisterResponse::class);
    }

    /**
     * Boots every configured panel provider.
     *
     * A panel that is listed twice is registered once: the registry keys by
     * panel id, and re-registering would run discovery a second time for no
     * change in outcome.
     */
    private function registerPanels(): void
    {
        $manager = $this->app->make(PanelManager::class);

        foreach ($this->configuredPanels() as $provider) {
            if (! $manager->has((new $provider)->panelId())) {
                $manager->registerProvider($provider);
            }
        }

        // After every panel is registered, so the check sees all of their
        // discovery paths. A no-op unless a manifest exists and this is a
        // development environment — see `PanelManifest::warnIfStale()`.
        $this->app->make(PanelManifest::class)
            ->warnIfStale($this->app->make(PanelRegistry::class));
    }

    private function registerMiddleware(): void
    {
        $router = $this->app->make(Router::class);

        foreach (self::MIDDLEWARE_ALIASES as $alias => $class) {
            $router->aliasMiddleware($alias, $class);
        }

        if ($this->app->make('config')->get('panda-panel.register_web_middleware') !== true) {
            return;
        }

        // Pushed onto the group rather than named in `bootstrap/app.php`, so
        // installing the package is enough. An application that needs them at
        // a particular position turns this off in config and registers them
        // itself.
        $this->pushWebMiddleware();
    }

    /**
     * Adds the web middleware to the `web` group, once each.
     *
     * Through the kernel rather than the router, and after the kernel
     * resolves rather than now. `bootstrap/app.php` configures the group in an
     * `afterResolving` hook on the kernel, which then overwrites whatever the
     * router was holding — a package that pushed straight onto the router
     * would have its middleware silently dropped on the way in. Registering a
     * later hook puts this after that one, which is the only ordering that
     * survives.
     */
    private function pushWebMiddleware(): void
    {
        $append = static function (mixed $kernel): void {
            if (! method_exists($kernel, 'appendMiddlewareToGroup')) {
                return;
            }

            foreach (self::WEB_MIDDLEWARE as $middleware) {
                $kernel->appendMiddlewareToGroup('web', $middleware);
            }
        };

        if ($this->app->resolved(Kernel::class)) {
            $append($this->app->make(Kernel::class));
        }

        $this->app->afterResolving(Kernel::class, $append);
    }

    private function registerRoutes(): void
    {
        if ($this->app->make('config')->get('panda-panel.register_routes') !== true) {
            return;
        }

        $this->app->make(PanelRouteRegistrar::class)->registerAll();
    }

    /**
     * Wires the model events every enabled resource fires its integrations on.
     *
     * At boot rather than in a page, because the point of hanging these off
     * Eloquent is that a record written from a console command or a queued job
     * fires them too — and neither of those has ever rendered a panel screen.
     *
     * A resource that has not opted in registers nothing at all, so the cost
     * of this feature to everybody else is one method call per resource during
     * boot.
     */
    private function registerIntegrations(): void
    {
        $manager = $this->app->make(PanelManager::class);

        foreach ($manager->all() as $panel) {
            $registry = $manager->resources($panel);

            foreach ($registry->all() as $resource) {
                /** @var class-string<Resources\Resource> $resource */
                $settings = $resource::integrationSettings();

                if (! $settings->enabled()) {
                    continue;
                }

                IntegrationObserver::register(
                    $resource::getModel(),
                    $panel->getId(),
                    $registry->slugFor($resource),
                    $settings,
                );
            }
        }
    }

    /**
     * The package's own strings, under the `panda-panel` namespace.
     *
     * Registered before anything else in `boot()`, because a panel provider
     * that builds its navigation during boot would otherwise ask for a
     * translation the translator has not been told where to find, and get the
     * key back instead of the sentence.
     *
     * The hint is the package's own `lang/`, which is what an application
     * that published nothing reads — including any locale a later version
     * adds. What an application *has* published is merged over the top by
     * `PanelTranslationLoader`, from `lang/{locale}` rather than from
     * `lang/vendor/panda-panel`.
     */
    private function registerTranslations(): void
    {
        $this->loadTranslationsFrom($this->packagePath('lang'), self::TRANSLATION_NAMESPACE);
    }

    /**
     * Teaches the translator to read the panel's strings from `lang/{locale}`.
     *
     * The framework looks for a namespaced override in exactly one place —
     * `lang/vendor/{namespace}/{locale}/{group}.php` — and that is where this
     * package used to publish. It is three directories away from where an
     * application keeps every other sentence it has written, so the publish
     * target is now `lang/{locale}` and this is what makes the framework find
     * it. See `PanelTranslationLoader` for the merge and for what the flat
     * layout costs.
     *
     * In `register()` rather than `boot()`, and through `extend()` rather than
     * a rebind: `translation.loader` is a deferred singleton, so extending it
     * before anything resolves it means the wrapper is in place the first time
     * a string is asked for — including one asked for by another package's
     * `boot()`.
     */
    private function registerTranslationLoader(): void
    {
        if ($this->app->make('config')->get('panda-panel.translations.publish_to_lang_root') !== true) {
            return;
        }

        $this->app->extend(
            'translation.loader',
            fn (Loader $loader, Application $app): Loader => new PanelTranslationLoader(
                $loader,
                $app->make('files'),
                static fn (): string => $app->langPath(),
                self::TRANSLATION_NAMESPACE,
                $this->packagePath('lang'),
            ),
        );
    }

    private function registerMigrations(): void
    {
        if ($this->app->make('config')->get('panda-panel.load_migrations') === true) {
            $this->loadMigrationsFrom($this->packagePath('database/migrations'));
        }
    }

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            $this->packagePath('config/panda-panel.php') => config_path('panda-panel.php'),
        ], ['panda-panel', 'panda-panel-config']);

        $this->publishes([
            $this->packagePath('database/migrations') => database_path('migrations'),
        ], ['panda-panel', 'panda-panel-migrations']);

        $this->publishes([
            $this->packagePath('stubs/panel') => base_path('stubs/panel'),
        ], 'panda-panel-stubs');

        // Publishing is for rewording. A locale the package does not ship
        // needs no publish at all — the panel reads its own `lang/` either
        // way — and the strings work untouched, which is why this is not part
        // of the umbrella install.
        //
        // Into `lang/{locale}`, beside the application's own strings, rather
        // than `lang/vendor/panda-panel` — see `registerTranslationLoader()`
        // for what makes the namespace resolve there.
        //
        // A published copy used to stop following the package: the file was
        // frozen at the version it was published, and a sentence improved
        // three releases later never arrived. It is tracked in
        // `.panel-assets.json` alongside the frontend now, so `panel:assets`
        // reports a published file the package has since changed, `--update`
        // writes back the ones this application never touched, and `--force`
        // takes the rest. Through `PublishedAssets` so the tag and the
        // upgrade path cannot name two different directories.
        $this->publishes(PublishedAssets::translations(), ['panda-panel', 'panda-panel-translations']);

        // The frontend is published rather than imported from the package: the
        // component registries are `import.meta.glob` allowlists over the
        // application's own tree, so a component the build never saw is a
        // component that cannot resolve. Published files are the
        // application's — in its repository, in its build, and editable.
        // Through `PublishedAssets` so that `vendor:publish` and
        // `panel:assets` read one list. Two copies of a publish map drift the
        // first time a directory is added, and the symptom is a file that
        // publishes but is never reported as out of date.
        $this->publishes(PublishedAssets::map(), ['panda-panel', 'panda-panel-assets']);
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            CachePanelsCommand::class,
            ClearPanelsCommand::class,
            InstallPanelCommand::class,
            MakePanelCommand::class,
            MakePanelResourceCommand::class,
            MakePanelPageCommand::class,
            MakePanelRelationManagerCommand::class,
            MakePanelUserCommand::class,
            MakePanelWidgetCommand::class,
            PanelAssetsCommand::class,
            PanelPluginsCommand::class,
            PublishPanelAssetsCommand::class,
            SyncPanelIconsCommand::class,
        ]);

        // Puts the panel manifest on the same footing as the config and route
        // caches, so a deploy that runs `optimize` gets it for free.
        $this->optimizes(
            optimize: 'panel:cache',
            clear: 'panel:clear',
            key: 'panels',
        );
    }

    /**
     * The panel providers named in config, filtered to the ones that exist.
     *
     * A class name that no longer resolves would fatal during boot — before
     * any route, including the one that would have shown the error. Skipping
     * it leaves the application reachable, and `panel:cache` reports the same
     * list where a mistake is actually visible.
     *
     * @return list<class-string<PanelProvider>>
     */
    private function configuredPanels(): array
    {
        $configured = $this->app->make('config')->get('panda-panel.panels', []);

        if (! is_array($configured)) {
            return [];
        }

        $panels = [];

        foreach ($configured as $provider) {
            if (is_string($provider) && is_subclass_of($provider, PanelProvider::class)) {
                $panels[] = $provider;
            }
        }

        return $panels;
    }

    private function packagePath(string $path): string
    {
        return dirname(__DIR__).'/'.$path;
    }
}
