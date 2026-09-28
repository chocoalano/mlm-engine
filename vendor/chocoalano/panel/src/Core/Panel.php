<?php

declare(strict_types=1);

namespace PandaPanel\Core;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PandaPanel\Actions\Action;
use PandaPanel\Broadcasting\NotificationChannel;
use PandaPanel\Contracts\PanelContract;
use PandaPanel\Contracts\PanelPlugin;
use PandaPanel\Contracts\PanelUser;
use PandaPanel\Enums\RenderHook;
use PandaPanel\Enums\SubNavigationPosition;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Pages\Dashboard;
use PandaPanel\Pages\Page;
use PandaPanel\Pages\Settings\AppearanceSettings;
use PandaPanel\Pages\Settings\ProfileSettings;
use PandaPanel\Pages\Settings\SecuritySettings;
use PandaPanel\Plugins\PluginCompatibility;
use PandaPanel\Resources\Resource;
use PandaPanel\Resources\ResourceConfiguration;
use PandaPanel\Support\CssHooks;
use PandaPanel\Support\Label;
use PandaPanel\Support\NavigationGroupName;
use PandaPanel\Support\PanelTheme;
use PandaPanel\Tenancy\Tenancy;
use UnitEnum;

/**
 * A panel's configuration.
 *
 * Fluent setters keep the bare names from the architecture document
 * (`->id()`, `->path()`, `->middleware()`); readers are prefixed with `get`.
 * That keeps one name from meaning two things, which a combined
 * setter/getter would require.
 *
 * Discovery paths and navigation groups accumulate rather than overwrite, so
 * a future module can contribute to a panel without core changes.
 */
final class Panel implements PanelContract
{
    /**
     * The account pages every panel gets unless `settings(false)` says
     * otherwise.
     *
     * @var list<class-string<Page>>
     */
    private const SETTINGS_PAGES = [
        ProfileSettings::class,
        SecuritySettings::class,
        AppearanceSettings::class,
    ];

    /**
     * The statuses a panel speaks about before any panel customizes it. A
     * status absent from here keeps Inertia's own error handling.
     *
     * @var list<int>
     */
    private const NOTIFIED_STATUSES = [403, 404, 419, 429, 500, 503];

    private ?string $id = null;

    private ?string $name = null;

    private ?string $path = null;

    private ?string $domain = null;

    /** @var list<string> */
    private array $middleware = ['web'];

    /** @var list<string> */
    private array $authMiddleware = ['auth'];

    /** @var list<class-string> */
    private array $resources = [];

    /** @var list<ResourceConfiguration> */
    private array $resourceConfigurations = [];

    /** @var list<class-string> */
    private array $pages = [];

    /** @var list<class-string> */
    private array $widgets = [];

    /** @var list<string> */
    private array $resourceDiscoveryPaths = [];

    /** @var list<string> */
    private array $pageDiscoveryPaths = [];

    /** @var list<string> */
    private array $widgetDiscoveryPaths = [];

    /** @var list<string> */
    private array $navigationGroups = [];

    /** @var array<string, string> child label => parent label */
    private array $navigationGroupParents = [];

    /**
     * The panel's name in the shell.
     *
     * A closure is evaluated on every render rather than once at boot, which
     * is the only way a brand can name the tenant this request is in. The
     * panel is configured in a service provider's `boot()`, long before any
     * middleware has resolved a tenant, so a string computed there would be
     * whichever tenant happened to be first — and under Octane it would then
     * be that tenant for every request the worker went on to serve.
     *
     * @var string|(Closure(): string)|null
     */
    private string|Closure|null $brandName = null;

    private ?string $brandLogo = null;

    private ?string $darkBrandLogo = null;

    private ?string $favicon = null;

    private ?string $darkFavicon = null;

    private ?string $icon = null;

    private ?string $darkIcon = null;

    /**
     * The locales this panel offers, or null to follow the config.
     *
     * @var array<string, string>|null
     */
    private ?array $locales = null;

    private bool $darkMode = true;

    /** @var array<string, PanelPlugin> */
    private array $plugins = [];

    /**
     * The tenant model this panel is scoped to, when it is scoped to one.
     *
     * Null is not "no tenancy configured yet" — it is a panel that has none,
     * which is most panels, and every tenancy check reads it as exactly that.
     *
     * @var class-string<Model>|null
     */
    private ?string $tenantModel = null;

    /**
     * How a recipient's broadcast channel is named, when the application
     * knows something about tenancy this package cannot see.
     *
     * @var Closure(Authenticatable): string|null
     */
    private ?Closure $broadcastChannelResolver = null;

    /**
     * How the tenant this request is in is named in the shell.
     *
     * A closure like `$brandName`, and for the same reason: the tenant does
     * not exist when the panel is configured. It receives the current tenant,
     * so the common case needs no service resolution at all.
     *
     * @var string|(Closure(Model): string)|null
     */
    private string|Closure|null $tenantLabel = null;

    /** @var Closure(Request, ?Authenticatable): ?Model|null */
    private ?Closure $tenantResolver = null;

    /** @var Closure(Model, self): string|null */
    private ?Closure $tenantUrl = null;

    private PanelTheme $theme;

    private CssHooks $cssHooks;

    private bool $sidebarCollapsible = true;

    private bool $sidebarDefaultOpen = true;

    /** @var 'sidebar'|'header' */
    private string $sidebarVariant = 'sidebar';

    /** @var 'sidebar'|'floating'|'inset' */
    private string $sidebarAppearance = 'inset';

    /**
     * CSS lengths, not numbers: a rail is measured in `rem` and a collapsed
     * one in whatever fits an icon, and the value goes straight into a custom
     * property rather than into a class the compiler has to have seen.
     */
    private string $sidebarWidth = '16rem';

    private string $collapsedSidebarWidth = '3rem';

    private bool $navigation = true;

    private bool $topbar = true;

    private bool $breadcrumbs = true;

    /** A build-time registry key, or null for the shell's own. */
    private ?string $sidebarComponent = null;

    private ?string $topbarComponent = null;

    /** @var list<array<string, mixed>> */
    private array $userMenuItems = [];

    private ?string $maxContentWidth = null;

    private bool $settings = true;

    private bool $databaseTransactions = true;

    private bool $strictAuthorization = false;

    private bool $unsavedChangesAlerts = true;

    /** @var list<Closure(self): void> */
    private array $bootCallbacks = [];

    /** @var 'hover'|'mount'|'click'|null */
    private ?string $prefetch = 'hover';

    /** @var list<string> */
    private array $fullPageUrls = [];

    /**
     * Status code to notification, or null for a status that must produce no
     * notification at all.
     *
     * @var array<int, array{title: string, body: string|null}|null>
     */
    private array $errorNotifications = [];

    private SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    /** @var list<string> */
    private array $assets = [];

    private bool $broadcasting = true;

    private bool $notifications = true;

    private bool $requiresTwoFactor = false;

    private bool $login = false;

    private bool $registration = false;

    private bool $passwordReset = false;

    private bool $emailVerification = false;

    private bool $globalSearch = true;

    /** Across every resource, not per resource. */
    private int $globalSearchLimit = 50;

    private int $globalSearchDebounce = 300;

    /** @var list<string> */
    private array $globalSearchKeyBindings = ['mod+k'];

    /**
     * Hook name to the components injected there, in registration order.
     *
     * @var array<string, list<array{component: string, data: array<string, mixed>, scopes: list<string>}>>
     */
    private array $renderHooks = [];

    /** @var class-string<Page> */
    private string $dashboard = Dashboard::class;

    /** @var list<class-string<Page>> */
    private array $extraDashboards = [];

    /** @var (Closure(?Authenticatable): bool)|null */
    private ?Closure $canAccess = null;

    /**
     * The two collaborators a panel always has.
     *
     * Objects rather than arrays because both validate what they are given —
     * a colour that is a stylesheet and a hook name the shell does not render
     * are both dropped where they arrive, not where they are used.
     */
    public function __construct()
    {
        $this->theme = new PanelTheme;
        $this->cssHooks = new CssHooks;
    }

    public static function make(?string $id = null): self
    {
        $panel = new self;

        return $id === null ? $panel : $panel->id($id);
    }

    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function path(string $path): self
    {
        $this->path = trim($path, '/');

        return $this;
    }

    public function domain(?string $domain): self
    {
        $this->domain = $domain;

        return $this;
    }

    /**
     * Replaces the base middleware stack.
     *
     * @param  list<string>  $middleware
     */
    public function middleware(array $middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }

    /**
     * Middleware appended after the base stack, for authentication concerns.
     *
     * @param  list<string>  $middleware
     */
    public function authMiddleware(array $middleware): self
    {
        $this->authMiddleware = $middleware;

        return $this;
    }

    /**
     * Shorthand for the project's authentication middleware.
     */
    /**
     * The panel's own front door.
     *
     * A login page at the panel's own path, carrying its brand. The form
     * posts to Fortify's endpoints, which already exist: duplicating the
     * login POST per panel would mean duplicating rate limiting, two-factor,
     * passkeys, and session handling — four things that must never disagree
     * between two doors into the same application.
     *
     * Turning this on also changes where a guest is *sent*: to this panel's
     * login rather than the application's, with the intended URL kept so they
     * land where they were going.
     */
    /**
     * Makes a second factor a condition of using this panel.
     *
     * Enforced by middleware rather than per page, because the point is the
     * pages a user has *not* reached: a check on each one would be a check
     * somebody has to remember to add to the next one. A passkey counts, so
     * somebody already using a hardware key is not asked to downgrade to a
     * code.
     */
    public function requireTwoFactor(bool $required = true): self
    {
        $this->requiresTwoFactor = $required;

        return $this;
    }

    public function requiresTwoFactor(): bool
    {
        return $this->requiresTwoFactor;
    }

    public function login(bool $login = true): self
    {
        $this->login = $login;

        return $this;
    }

    public function registration(bool $registration = true): self
    {
        $this->registration = $registration;

        return $this;
    }

    public function passwordReset(bool $passwordReset = true): self
    {
        $this->passwordReset = $passwordReset;

        return $this;
    }

    public function emailVerification(bool $emailVerification = true): self
    {
        $this->emailVerification = $emailVerification;

        return $this;
    }

    public function hasLogin(): bool
    {
        return $this->login;
    }

    public function hasRegistration(): bool
    {
        return $this->registration;
    }

    public function hasPasswordReset(): bool
    {
        return $this->passwordReset;
    }

    public function hasEmailVerification(): bool
    {
        return $this->emailVerification;
    }

    public function auth(bool $verified = true): self
    {
        $stack = ['auth'];

        if ($verified) {
            $stack[] = 'verified';
        }

        return $this->authMiddleware($this->mergePaths($this->authMiddleware, $stack));
    }

    /**
     * Resources this panel registers.
     *
     * A bare class takes its own defaults. A `ResourceConfiguration` is the
     * same class configured for this panel: a different slug, a different
     * place in the sidebar, a narrower query.
     *
     * @param  array<array-key, class-string|ResourceConfiguration>  $resources
     */
    public function resources(array $resources): self
    {
        foreach (array_values($resources) as $resource) {
            if ($resource instanceof ResourceConfiguration) {
                $this->resourceConfigurations[] = $resource;

                continue;
            }

            $this->resources = $this->mergeClasses($this->resources, [$resource]);
        }

        return $this;
    }

    /**
     * @return list<ResourceConfiguration>
     */
    public function getResourceConfigurations(): array
    {
        return $this->resourceConfigurations;
    }

    /**
     * @param  list<class-string>  $pages
     */
    public function pages(array $pages): self
    {
        $this->pages = $this->mergeClasses($this->pages, $pages);

        return $this;
    }

    /**
     * @param  list<class-string>  $widgets
     */
    public function widgets(array $widgets): self
    {
        $this->widgets = $this->mergeClasses($this->widgets, $widgets);

        return $this;
    }

    public function discoverResources(string ...$paths): self
    {
        $this->resourceDiscoveryPaths = $this->mergePaths($this->resourceDiscoveryPaths, $paths);

        return $this;
    }

    public function discoverPages(string ...$paths): self
    {
        $this->pageDiscoveryPaths = $this->mergePaths($this->pageDiscoveryPaths, $paths);

        return $this;
    }

    public function discoverWidgets(string ...$paths): self
    {
        $this->widgetDiscoveryPaths = $this->mergePaths($this->widgetDiscoveryPaths, $paths);

        return $this;
    }

    /**
     * Declares sidebar group order. Groups not declared here are appended
     * alphabetically by the navigation builder.
     *
     * @param  list<string>  $groups
     */
    /**
     * The order sidebar groups appear in, and which of them nest.
     *
     * A group may be named by a string or by a backed enum — an enum is worth
     * reaching for once more than one class names the same group, because a
     * mistyped string is a second group that looks like the first while a
     * mistyped enum case does not compile.
     *
     * `'Access' => 'System'` nests Access under System. A nested group is
     * still one group with one set of items; the sidebar draws it indented
     * under its parent rather than as a second heading at the top level.
     *
     * @param  array<array-key, string|BackedEnum|UnitEnum>  $groups
     */
    public function navigationGroups(array $groups): self
    {
        $labels = [];

        foreach ($groups as $key => $group) {
            $label = NavigationGroupName::resolve($group);

            if ($label === null) {
                continue;
            }

            $labels[] = $label;

            // A string key names the parent this group nests under.
            if (is_string($key)) {
                $this->navigationGroupParents[NavigationGroupName::resolve($key) ?? $key] = $label;
            }
        }

        $this->navigationGroups = $this->mergePaths($this->navigationGroups, $labels);

        return $this;
    }

    /**
     * Which group each nested group belongs under, keyed by the child.
     *
     * @return array<string, string>
     */
    public function getNavigationGroupParents(): array
    {
        return $this->navigationGroupParents;
    }

    /**
     * Names the panel, statically or per request.
     *
     *     ->brandName('HRMS')
     *     ->brandName(fn () => Tenancy::current()?->name ?? 'HRMS')
     *
     * The closure runs while the response is being built, so it sees the
     * tenant, the user, and the locale — none of which exist yet when the
     * panel is configured. Nothing is memoized: a value cached on this object
     * would outlive the request under Octane and show one tenant's name to
     * the next.
     *
     * @param  string|(Closure(): string)  $brandName
     */
    public function brandName(string|Closure $brandName): self
    {
        $this->brandName = $brandName;

        return $this;
    }

    public function brandLogo(?string $brandLogo, ?string $darkBrandLogo = null): self
    {
        $this->brandLogo = $brandLogo;

        if ($darkBrandLogo !== null) {
            $this->darkBrandLogo = $darkBrandLogo;
        }

        return $this;
    }

    public function darkBrandLogo(?string $brandLogo): self
    {
        $this->darkBrandLogo = $brandLogo;

        return $this;
    }

    /**
     * The panel's colours.
     *
     * CSS custom properties, which is how this application's stylesheet is
     * already written — so a panel carries its own theme without anything
     * being compiled and without a second stylesheet to keep in step.
     *
     * Deliberately unlike `BadgeColor`: that is a closed set of *meanings*,
     * each of which needs a literal class. These are *values*, and a value
     * never becomes a class.
     *
     * @param  array<string, string>  $light
     * @param  array<string, string>  $dark
     */
    public function colors(array $light, array $dark = []): self
    {
        $this->theme->light($light);

        if ($dark !== []) {
            $this->theme->dark($dark);
        }

        return $this;
    }

    /**
     * Extra classes on named parts of the shell.
     *
     * Every part already carries a stable `panel-*` class; this adds to them,
     * so two panels sharing a build can look different without either one
     * shipping a component.
     *
     * @param  array<string, string>  $classes  keyed by hook name
     */
    public function cssHooks(array $classes): self
    {
        $this->cssHooks->add($classes);

        return $this;
    }

    /**
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    public function getTheme(): array
    {
        return $this->theme->toArray();
    }

    /**
     * @return array<string, string>
     */
    public function getCssHooks(): array
    {
        return $this->cssHooks->toArray();
    }

    public function favicon(?string $favicon, ?string $darkFavicon = null): self
    {
        $this->favicon = $favicon;

        if ($darkFavicon !== null) {
            $this->darkFavicon = $darkFavicon;
        }

        return $this;
    }

    public function darkFavicon(?string $favicon): self
    {
        $this->darkFavicon = $favicon;

        return $this;
    }

    /**
     * An icon registry key, never a component class or path.
     */
    public function icon(?string $icon, ?string $darkIcon = null): self
    {
        $this->icon = $icon;

        if ($darkIcon !== null) {
            $this->darkIcon = $darkIcon;
        }

        return $this;
    }

    public function darkIcon(?string $icon): self
    {
        $this->darkIcon = $icon;

        return $this;
    }

    public function darkMode(bool $darkMode = true): self
    {
        $this->darkMode = $darkMode;

        return $this;
    }

    /**
     * Which languages this panel may be read in, as `code => name`.
     *
     * Narrows `panda-panel.locales` for a panel that serves a different
     * audience from the rest — a customer portal in two languages beside an
     * internal admin in one. Unset, the panel offers whatever the config
     * does; an empty array offers none, and no switcher renders.
     *
     * @param  array<string, string>  $locales
     */
    public function locales(array $locales): self
    {
        $this->locales = $locales;

        return $this;
    }

    /**
     * `$variant` picks the shell: `header` renders top navigation instead of a
     * side rail. The two values match the starter kit's own `AppVariant`, so
     * panels and the existing app shell speak the same language.
     *
     * `$appearance` styles that rail and is ignored by the header shell:
     * `inset` floats the content pane inside the sidebar background,
     * `floating` detaches the rail as a rounded card, `sidebar` keeps it flush
     * against the edge with a border.
     *
     * @param  'sidebar'|'header'  $variant
     * @param  'sidebar'|'floating'|'inset'  $appearance
     */
    public function sidebar(bool $collapsible = true, bool $defaultOpen = true, string $variant = 'sidebar', string $appearance = 'inset'): self
    {
        $this->sidebarCollapsible = $collapsible;
        $this->sidebarDefaultOpen = $defaultOpen;
        $this->sidebarVariant = $variant;
        $this->sidebarAppearance = $appearance;

        return $this;
    }

    /**
     * Top navigation instead of a side rail.
     *
     * The same thing as `sidebar(variant: 'header')` said the way people
     * think about it — "this panel has a top bar" is a statement about the
     * panel, not a setting on the sidebar it does not have.
     */
    public function topNavigation(bool $topNavigation = true): self
    {
        $this->sidebarVariant = $topNavigation ? 'header' : 'sidebar';

        return $this;
    }

    /**
     * How wide the rail is, and how wide it is once collapsed.
     *
     * CSS lengths, because they become custom properties. A number would have
     * to become a class, and a class built by interpolation would not exist
     * in the bundle.
     */
    public function sidebarWidth(string $width, ?string $collapsedWidth = null): self
    {
        $this->sidebarWidth = $width;

        if ($collapsedWidth !== null) {
            $this->collapsedSidebarWidth = $collapsedWidth;
        }

        return $this;
    }

    public function collapsedSidebarWidth(string $width): self
    {
        $this->collapsedSidebarWidth = $width;

        return $this;
    }

    /**
     * Turns off a piece of the shell.
     *
     * A panel with one page has nothing to navigate; a kiosk has no use for
     * breadcrumbs. Turning one off removes it rather than hiding it, so
     * nothing is rendered and nothing is sent.
     */
    public function navigation(bool $navigation = true): self
    {
        $this->navigation = $navigation;

        return $this;
    }

    public function topbar(bool $topbar = true): self
    {
        $this->topbar = $topbar;

        return $this;
    }

    public function breadcrumbs(bool $breadcrumbs = true): self
    {
        $this->breadcrumbs = $breadcrumbs;

        return $this;
    }

    /**
     * Replaces the shell's own sidebar or topbar with a component of this
     * application's.
     *
     * A build-time registry key under `resources/js/pages/Panels/{Panel}/
     * Shell/`, never markup and never a path — the same rule custom columns,
     * fields, and widgets follow. It is handed the same navigation the
     * built-in one gets, so a replacement is a different *drawing* of the
     * panel rather than a second source of truth about it.
     */
    public function sidebarComponent(?string $component): self
    {
        $this->sidebarComponent = $component;

        return $this;
    }

    public function topbarComponent(?string $component): self
    {
        $this->topbarComponent = $component;

        return $this;
    }

    /**
     * Extra entries in the account menu.
     *
     * Links the server produced, never action names: this menu is rendered on
     * every page of the panel, and whatever an entry points at authorizes for
     * itself when it is followed.
     *
     * @param  array<array-key, array{label: string, url: string, icon?: string|null}>  $items
     */
    public function userMenuItems(array $items): self
    {
        foreach (array_values($items) as $item) {
            $this->userMenuItems[] = [
                'label' => $item['label'],
                'url' => $item['url'],
                'icon' => $item['icon'] ?? null,
            ];
        }

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUserMenuItems(): array
    {
        return $this->userMenuItems;
    }

    public function hasNavigation(): bool
    {
        return $this->navigation;
    }

    public function hasTopbar(): bool
    {
        return $this->topbar;
    }

    public function hasBreadcrumbs(): bool
    {
        return $this->breadcrumbs;
    }

    /**
     * The account settings pages every panel gets: profile, security, and
     * appearance, each in this panel's own shell.
     *
     * On by default because a signed-in user can always reach their own
     * account. A panel that has no business showing them — a kiosk, a
     * single-purpose reporting panel — turns them off here.
     */
    public function settings(bool $settings = true): self
    {
        $this->settings = $settings;

        return $this;
    }

    /**
     * Wraps every write this panel performs in a transaction: resource
     * create and update, and each action's hooks and handler.
     *
     * On by default, unlike Filament, because a half-applied write is worse
     * than a slow one and the pages already behaved this way. Turn it off
     * for a panel writing to something a transaction cannot cover, such as an
     * external API, and take responsibility per page or per action instead.
     */
    public function databaseTransactions(bool $databaseTransactions = true): self
    {
        $this->databaseTransactions = $databaseTransactions;

        return $this;
    }

    /**
     * Turns a missing policy into a loud failure instead of a silent denial.
     *
     * Off by default because it changes a 403 into a 500. On, it is a
     * development aid: a resource whose model has no policy, or whose policy
     * is missing the ability being checked, is a mistake that otherwise looks
     * exactly like a correct refusal.
     */
    public function strictAuthorization(bool $strictAuthorization = true): self
    {
        $this->strictAuthorization = $strictAuthorization;

        return $this;
    }

    /**
     * Warns before leaving a create or edit form with unsaved edits.
     */
    public function unsavedChangesAlerts(bool $unsavedChangesAlerts = true): self
    {
        $this->unsavedChangesAlerts = $unsavedChangesAlerts;

        return $this;
    }

    /**
     * When navigation links prefetch the page they point at.
     *
     * Inertia is already a single-page application, so there is no SPA mode
     * to turn on here; prefetching is the part of Filament's `spa()` that
     * carries over. `hover` by default, which costs a request only for links
     * the pointer actually rests on.
     *
     * @param  bool|'hover'|'mount'|'click'  $prefetch
     */
    public function prefetch(bool|string $prefetch = 'hover'): self
    {
        $this->prefetch = match ($prefetch) {
            false => null,
            true => 'hover',
            default => $prefetch,
        };

        return $this;
    }

    /**
     * Paths that must be a real browser navigation rather than a client-side
     * visit — a download, a route that sets headers the SPA would discard, an
     * external application.
     *
     * Patterns accept `*`, and matching happens here rather than in Vue so
     * the decision arrives with the link instead of being re-derived per
     * render.
     */
    public function fullPageUrls(string ...$patterns): self
    {
        $this->fullPageUrls = $this->mergePaths($this->fullPageUrls, $patterns);

        return $this;
    }

    /**
     * Replaces the notification shown for an HTTP status.
     */
    public function errorNotification(int $status, string $title, ?string $body = null): self
    {
        $this->errorNotifications[$status] = ['title' => $title, 'body' => $body];

        return $this;
    }

    /**
     * Silences a status entirely: no notification, and no error overlay
     * either. For a status the application handles itself.
     */
    public function hideErrorNotification(int $status): self
    {
        $this->errorNotifications[$status] = null;

        return $this;
    }

    /**
     * The command palette above every panel page.
     *
     * On by default, but it searches nothing until a resource declares
     * `$globalSearchAttributes`, so a panel with no searchable resource
     * shows no palette at all.
     *
     * `$limit` is across the whole search, not per resource. `$debounce` is
     * milliseconds. Key bindings use `mod` for the platform's command key.
     *
     * @param  list<string>  $keyBindings
     */
    public function globalSearch(
        bool $enabled = true,
        int $limit = 50,
        int $debounce = 300,
        array $keyBindings = ['mod+k'],
    ): self {
        $this->globalSearch = $enabled;
        $this->globalSearchLimit = $limit;
        $this->globalSearchDebounce = $debounce;
        $this->globalSearchKeyBindings = $keyBindings;

        return $this;
    }

    /**
     * Whether this panel opens a websocket for its own notifications.
     *
     * On by default, but nothing connects until a page actually subscribes,
     * so a panel that turns this off costs no connection at all. Turn it off
     * for a panel that has nothing to receive, or one served where no
     * broadcaster is reachable.
     */
    /**
     * Decides the broadcast channel a recipient's notifications travel on.
     *
     *     ->broadcastChannelUsing(fn (Authenticatable $user) => sprintf(
     *         'tenants.%s.users.%s',
     *         app(CurrentTenant::class)->get()->getKey(),
     *         $user->getAuthIdentifier(),
     *     ))
     *
     * Needed when tenants are identified by something this package cannot
     * see. A panel using this package's own tenancy is isolated already —
     * see `NotificationChannel` — but an application identifying tenants
     * through another library has to say so, because a user id is unique
     * only inside one database and broadcasting is one shared namespace.
     *
     * A closure, evaluated per resolution rather than at boot, for the reason
     * `brandName()` takes one: the tenant does not exist when the panel is
     * configured, and a value captured then would be whichever tenant a
     * worker served first.
     *
     * Returning null or an empty string throws rather than falling back — a
     * fallback would be the bare user id, which is what the resolver was
     * declared to prevent.
     *
     * @param  Closure(Authenticatable): string  $resolver
     */
    public function broadcastChannelUsing(Closure $resolver): self
    {
        $this->broadcastChannelResolver = $resolver;

        return $this;
    }

    /**
     * @return Closure(Authenticatable): string|null
     */
    public function getBroadcastChannelResolver(): ?Closure
    {
        return $this->broadcastChannelResolver;
    }

    public function broadcasting(bool $broadcasting = true): self
    {
        $this->broadcasting = $broadcasting;

        return $this;
    }

    /**
     * Whether this panel shows the notification bell.
     *
     * On by default. A panel that turns it off keeps the endpoints — a job
     * can still write a notification a user reads in another panel — but the
     * control is absent rather than present and empty.
     */
    public function notifications(bool $notifications = true): self
    {
        $this->notifications = $notifications;

        return $this;
    }

    public function hasNotifications(): bool
    {
        return $this->notifications;
    }

    /**
     * Vite entrypoints loaded only on this panel's pages.
     *
     * Paths, not built files: they must also appear in `vite.config.ts`'s
     * `input`, or Vite has nothing to serve and the page fails with a
     * manifest error. That failure is the right one — a declared asset that
     * was never built is a mistake, not something to paper over — but it is
     * the reason this list is a deliberate pair of edits rather than one.
     *
     * Accumulates, so a module can add a stylesheet without displacing the
     * panel's own.
     */
    public function assets(string ...$entrypoints): self
    {
        $this->assets = $this->mergePaths($this->assets, $entrypoints);

        return $this;
    }

    /**
     * Where a record's sub-navigation sits, for every resource in the panel
     * that does not state its own.
     */
    public function subNavigationPosition(SubNavigationPosition $position): self
    {
        $this->subNavigationPosition = $position;

        return $this;
    }

    /**
     * Injects a Vue component at a point in the panel shell.
     *
     * `$component` is a registry key under
     * `resources/js/pages/Panels/{Panel}/Hooks/`, never a path or a class,
     * for the same reason widget components are: an unregistered name must
     * be unable to reach anything.
     *
     * `$scopes` narrows a hook to particular pages. Pass resource or page
     * classes and they are reduced to slugs here, so no class name is ever
     * serialized. An empty list means every page in the panel.
     *
     * @param  array<string, mixed>  $data
     * @param  list<class-string|string>  $scopes
     */
    public function renderHook(RenderHook $hook, string $component, array $data = [], array $scopes = []): self
    {
        $this->renderHooks[$hook->value][] = [
            'component' => $component,
            'data' => $data,
            'scopes' => array_map(self::scopeFor(...), $scopes),
        ];

        return $this;
    }

    /**
     * A resource or page class reduces to the same string its pages report
     * in their metadata; anything else is taken as already being one.
     *
     * @param  class-string|string  $scope
     */
    private static function scopeFor(string $scope): string
    {
        if (is_subclass_of($scope, Resource::class)) {
            return 'resource:'.$scope::slug();
        }

        if (is_subclass_of($scope, Page::class)) {
            return 'page:'.$scope::slug();
        }

        return $scope;
    }

    /**
     * Runs on every request into this panel, after access is granted.
     *
     * Callbacks accumulate, so a module can add one without displacing
     * another. They run after the access check on purpose: a user who is
     * refused the panel must not trigger its boot work.
     *
     * @param  Closure(self): void  $callback
     */
    /** @var (Closure(Action): void)|null */
    private ?Closure $actionConfigurator = null;

    /**
     * A default applied to every action this panel builds.
     *
     * For house style across a panel — every destructive action confirming,
     * every action carrying an icon — without each schema repeating it. The
     * closure receives each action as it is made, so anything it sets can
     * still be overridden by the schema that made it.
     *
     * Request-scoped through the current panel rather than a static
     * configurator, so two panels can differ and nothing leaks between
     * requests.
     *
     * @param  Closure(Action): void  $callback
     */
    public function configureActions(Closure $callback): self
    {
        $this->actionConfigurator = $callback;

        return $this;
    }

    /**
     * @return (Closure(Action): void)|null
     */
    public function actionConfigurator(): ?Closure
    {
        return $this->actionConfigurator;
    }

    /**
     * Declares this panel tenant-scoped.
     *
     * What it turns on is narrow and worth being precise about:
     *
     * - Every route group this panel registers gets `ResolveTenant`, which
     *   identifies the tenant, checks the user against it, and binds it
     *   before any controller runs.
     * - `Tenancy::current()` answers for the rest of the request.
     * - A resource that names a `tenantRelationship()` is scoped
     *   automatically, and one that does not is left exactly as it was.
     * - The switcher's list is shared with the frontend.
     *
     * What it deliberately does not do is decide what a tenant *is*. It does
     * not switch a connection, partition a cache, or read a subdomain —
     * `stancl/tenancy` does all three and `docs/tenancy/stancl-tenancy.md` is the
     * guide for putting the two together. The `$resolver` is where the two
     * meet, and it is required rather than defaulted because every plausible
     * default is right for one arrangement and a silent data leak in another:
     *
     *   // Database per tenant, identified by subdomain: stancl has already
     *   // switched the connection by the time this runs.
     *   ->tenant(Tenant::class, fn () => tenant())
     *
     *   // One database, tenants on a path segment.
     *   ->tenant(Team::class, fn (Request $request) => Team::query()
     *       ->where('slug', $request->route('team'))
     *       ->first())
     *
     *   // One tenant per user, nothing in the URL at all.
     *   ->tenant(Workspace::class, fn ($request, $user) => $user?->workspace)
     *
     * The resolver receives the request and the authenticated user, and
     * returns a model or null. Null is a 404: the request named a tenant that
     * does not exist, or named none when one was required.
     *
     * @param  class-string<Model>  $model
     * @param  Closure(Request, ?Authenticatable): ?Model  $resolver
     */
    public function tenant(string $model, Closure $resolver): self
    {
        $this->tenantModel = $model;
        $this->tenantResolver = $resolver;

        return $this;
    }

    /**
     * Where a given tenant lives, for the switcher.
     *
     * The other half of `tenant()`, and the application's for the same
     * reason: a panel that identifies tenants by subdomain has tenant URLs on
     * subdomains, and one that reads a path segment has them in the path.
     * Only the resolver's author knows which, so only they can reverse it.
     *
     *   ->tenantUrlUsing(fn (Team $team) => "https://{$team->slug}.example.com/app")
     *   ->tenantUrlUsing(fn (Team $team, Panel $panel) => "/{$panel->getPath()}/{$team->slug}")
     *
     * Without one the switcher does not render. That is deliberate: a
     * switcher whose entries went nowhere would be worse than no switcher,
     * and a URL this framework guessed would go somewhere wrong.
     *
     * @param  Closure(Model, self): string  $url
     */
    public function tenantUrlUsing(Closure $url): self
    {
        $this->tenantUrl = $url;

        return $this;
    }

    /**
     * One tenant's URL, or null when the panel never said how to build one.
     */
    /**
     * Names the current tenant in the shell.
     *
     *     ->tenantLabel(fn (Model $company) => $company->legal_name)
     *
     * Separate from the switcher, which is the distinction this exists to
     * make. A user who belongs to one tenant has nothing to switch to and so
     * saw no tenant anywhere in the shell — the company they were working in
     * was simply not on screen, and applications worked around it by putting
     * the name in a page heading. Identity and navigation are two questions:
     * "which company am I in" is always worth answering, "which others could
     * I be in" only when there are others.
     *
     * Defaults to the tenant's own name — see `Tenancy::nameOf()`.
     *
     * @param  string|(Closure(Model): string)|null  $label
     */
    public function tenantLabel(string|Closure|null $label): self
    {
        $this->tenantLabel = $label;

        return $this;
    }

    /**
     * The label for one tenant, resolved now rather than at boot.
     */
    public function getTenantLabel(?Model $tenant): ?string
    {
        if ($tenant === null) {
            return is_string($this->tenantLabel) ? $this->tenantLabel : null;
        }

        if ($this->tenantLabel instanceof Closure) {
            return (string) ($this->tenantLabel)($tenant);
        }

        return $this->tenantLabel ?? Tenancy::nameOf($tenant);
    }

    public function getTenantUrl(Model $tenant): ?string
    {
        return $this->tenantUrl === null ? null : ($this->tenantUrl)($tenant, $this);
    }

    public function hasTenancy(): bool
    {
        return $this->tenantModel !== null;
    }

    /**
     * @return class-string<Model>|null
     */
    public function getTenantModel(): ?string
    {
        return $this->tenantModel;
    }

    /**
     * The tenant for one request, through the panel's own resolver.
     *
     * Called by `ResolveTenant` and by nothing else. A resolver that returns
     * something other than the declared model is treated as no tenant: a
     * mistyped resolver returning the *user* would otherwise scope every
     * query by a user id and look, at a glance, like it worked.
     */
    public function resolveTenant(Request $request, ?Authenticatable $user): ?Model
    {
        $model = $this->tenantModel;
        $resolver = $this->tenantResolver;

        if ($resolver === null || $model === null) {
            return null;
        }

        $tenant = $resolver($request, $user);

        return $tenant instanceof $model ? $tenant : null;
    }

    /**
     * Reusable bundles of panel configuration.
     *
     * A plugin configures the panel through the panel's own public API and
     * nothing else — no second configuration surface, which is what stops a
     * plugin doing something a panel cannot.
     *
     * `register()` runs now, while the panel is being built. `boot()` runs
     * later, once the panel has been resolved for a request: that is where
     * anything needing the container, the user, or a URL belongs.
     *
     * @param  array<array-key, PanelPlugin>  $plugins
     */
    public function plugins(array $plugins): self
    {
        foreach (array_values($plugins) as $plugin) {
            $id = $plugin->id();

            if (isset($this->plugins[$id])) {
                throw PanelRegistrationException::duplicatePlugin($id, $this->getId());
            }

            // Before `register()`, which is the last moment before the plugin
            // starts changing the panel — and the earliest at which the
            // answer is knowable.
            PluginCompatibility::assert($plugin, $this->getId());

            $this->plugins[$id] = $plugin;

            $plugin->register($this);
        }

        return $this;
    }

    /**
     * @return array<string, PanelPlugin>
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    public function hasPlugin(string $id): bool
    {
        return isset($this->plugins[$id]);
    }

    /**
     * One plugin, so a resource can ask the panel it is in how the plugin
     * that supplied it was configured.
     */
    public function plugin(string $id): ?PanelPlugin
    {
        return $this->plugins[$id] ?? null;
    }

    public function bootUsing(Closure $callback): self
    {
        $this->bootCallbacks[] = $callback;

        return $this;
    }

    public function maxContentWidth(?string $maxContentWidth): self
    {
        $this->maxContentWidth = $maxContentWidth;

        return $this;
    }

    /**
     * The page rendered at the panel root.
     *
     * A Page class rather than a component name, so the dashboard gets the
     * same metadata, authorization, and widget handling as every other page.
     *
     * @param  class-string<Page>  $page
     */
    public function dashboard(string $page): self
    {
        $this->dashboard = $page;

        return $this;
    }

    /**
     * More than one dashboard.
     *
     * The first is the panel root; the rest get routes of their own under it.
     * A panel with an operations dashboard and a finance dashboard is two
     * pages of widgets, not one page with a filter — they answer different
     * questions and are read by different people.
     *
     * They are Page classes like any other, so each one authorizes, appears
     * in navigation, and carries its own filters independently.
     *
     * @param  array<array-key, class-string<Page>>  $pages
     */
    public function dashboards(array $pages): self
    {
        $pages = array_values($pages);

        if ($pages === []) {
            return $this;
        }

        $this->dashboard = $pages[0];
        $this->extraDashboards = array_slice($pages, 1);

        return $this;
    }

    /**
     * The dashboards beyond the root one, which are registered as pages.
     *
     * @return list<class-string<Page>>
     */
    public function getExtraDashboards(): array
    {
        return $this->extraDashboards;
    }

    /**
     * @param  Closure(?Authenticatable): bool  $callback
     */
    public function canAccess(Closure $callback): self
    {
        $this->canAccess = $callback;

        return $this;
    }

    public function getId(): string
    {
        return $this->id ?? throw PanelRegistrationException::missingPanelId();
    }

    public function getName(): string
    {
        return $this->name ?? Label::resolve(
            'panels',
            $this->getId(),
            fn (): string => Str::headline($this->getId()),
        );
    }

    public function getPath(): string
    {
        return $this->path ?? $this->getId();
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /**
     * @return list<string>
     */
    public function getMiddleware(): array
    {
        return array_values(array_unique([...$this->middleware, ...$this->authMiddleware]));
    }

    /**
     * @return list<string>
     */
    public function getBaseMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * @return list<string>
     */
    public function getAuthMiddleware(): array
    {
        return $this->authMiddleware;
    }

    /**
     * @return list<class-string>
     */
    public function getResources(): array
    {
        return $this->resources;
    }

    /**
     * Explicitly registered pages, with the built-in settings pages first
     * when they are enabled.
     *
     * They join here rather than in the manager so discovery, caching, and
     * route registration treat them exactly like any other page.
     *
     * @return list<class-string>
     */
    public function getPages(): array
    {
        return $this->settings
            ? $this->mergeClasses(self::SETTINGS_PAGES, $this->pages)
            : $this->pages;
    }

    public function hasSettings(): bool
    {
        return $this->settings;
    }

    public function hasDatabaseTransactions(): bool
    {
        return $this->databaseTransactions;
    }

    public function hasStrictAuthorization(): bool
    {
        return $this->strictAuthorization;
    }

    public function hasUnsavedChangesAlerts(): bool
    {
        return $this->unsavedChangesAlerts;
    }

    /**
     * @return 'hover'|'mount'|'click'|null
     */
    public function getPrefetch(): ?string
    {
        return $this->prefetch;
    }

    /**
     * @return list<string>
     */
    public function getFullPageUrls(): array
    {
        return $this->fullPageUrls;
    }

    /**
     * Absolute URLs are matched on their path, so a pattern written as a path
     * still catches a link the server generated in full.
     */
    public function isFullPageUrl(string $url): bool
    {
        if ($this->fullPageUrls === []) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return Str::is($this->fullPageUrls, $url)
            || (is_string($path) && Str::is($this->fullPageUrls, $path));
    }

    /**
     * The panel's own entries merged over the framework defaults, so a panel
     * customizes one status without restating the rest.
     *
     * @return array<int, array{title: string, body: string|null}|null>
     */
    public function getErrorNotifications(): array
    {
        return array_replace($this->defaultErrorNotifications(), $this->errorNotifications);
    }

    /**
     * Built per call rather than held in a constant, because the sentences
     * are translated and a constant is resolved before the translator knows
     * which locale the request is in. It is read once per panel response,
     * from `toSharedArray()`.
     *
     * @return array<int, array{title: string, body: string|null}>
     */
    private function defaultErrorNotifications(): array
    {
        $notifications = [];

        foreach (self::NOTIFIED_STATUSES as $status) {
            $notifications[$status] = [
                'title' => __("panda-panel::notifications.http.{$status}.title"),
                'body' => __("panda-panel::notifications.http.{$status}.body"),
            ];
        }

        return $notifications;
    }

    public function hasBroadcasting(): bool
    {
        return $this->broadcasting;
    }

    public function hasGlobalSearch(): bool
    {
        return $this->globalSearch;
    }

    public function getGlobalSearchLimit(): int
    {
        return $this->globalSearchLimit;
    }

    public function getGlobalSearchDebounce(): int
    {
        return $this->globalSearchDebounce;
    }

    /**
     * @return list<string>
     */
    public function getGlobalSearchKeyBindings(): array
    {
        return $this->globalSearchKeyBindings;
    }

    /**
     * What this panel subscribes to, for the user making the request.
     *
     * Null when broadcasting is off or nobody is signed in, so the frontend
     * has nothing to subscribe to rather than a channel it would be refused.
     */
    public function getBroadcastChannel(?Authenticatable $user): ?string
    {
        return $this->broadcasting && $user !== null
            ? NotificationChannel::for($user, $this)
            : null;
    }

    /**
     * @return list<string>
     */
    public function getAssets(): array
    {
        return $this->assets;
    }

    public function getSubNavigationPosition(): SubNavigationPosition
    {
        return $this->subNavigationPosition;
    }

    /**
     * @return array<string, list<array{component: string, data: array<string, mixed>, scopes: list<string>}>>
     */
    public function getRenderHooks(): array
    {
        return $this->renderHooks;
    }

    /**
     * @return list<Closure(self): void>
     */
    public function getBootCallbacks(): array
    {
        return $this->bootCallbacks;
    }

    /**
     * Runs the boot callbacks. Called by `ResolvePanel`, once per request,
     * never during registration.
     */
    public function boot(): void
    {
        // Plugins first: a boot callback on the panel is the application's
        // own last word, and it should be able to undo what a plugin did.
        foreach ($this->plugins as $plugin) {
            $plugin->boot($this);
        }

        foreach ($this->bootCallbacks as $callback) {
            $callback($this);
        }
    }

    /**
     * @return list<class-string>
     */
    public function getWidgets(): array
    {
        return $this->widgets;
    }

    /**
     * @return list<string>
     */
    public function getResourceDiscoveryPaths(): array
    {
        return $this->resourceDiscoveryPaths;
    }

    /**
     * @return list<string>
     */
    public function getPageDiscoveryPaths(): array
    {
        return $this->pageDiscoveryPaths;
    }

    /**
     * @return list<string>
     */
    public function getWidgetDiscoveryPaths(): array
    {
        return $this->widgetDiscoveryPaths;
    }

    /**
     * @return list<string>
     */
    public function getNavigationGroups(): array
    {
        return $this->navigationGroups;
    }

    public function getBrandName(): string
    {
        if ($this->brandName instanceof Closure) {
            return (string) ($this->brandName)();
        }

        return $this->brandName ?? (string) config('app.name');
    }

    public function getBrandLogo(): ?string
    {
        return $this->brandLogo;
    }

    public function getDarkBrandLogo(): ?string
    {
        return $this->darkBrandLogo;
    }

    public function getFavicon(): ?string
    {
        return $this->favicon;
    }

    public function getDarkFavicon(): ?string
    {
        return $this->darkFavicon;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getDarkIcon(): ?string
    {
        return $this->darkIcon;
    }

    public function hasDarkMode(): bool
    {
        return $this->darkMode;
    }

    /**
     * The locales this panel offers, from the panel or from the config.
     *
     * @return array<string, string>
     */
    public function getLocales(): array
    {
        if ($this->locales !== null) {
            return $this->locales;
        }

        $configured = config('panda-panel.locales', []);

        // Only well-formed entries. A list rather than a map — `['en', 'id']`
        // — is the shape somebody writes first, and a switcher whose labels
        // were `0` and `1` is worse than one that is simply absent.
        return is_array($configured)
            ? array_filter(
                $configured,
                static fn (mixed $name, mixed $code): bool => is_string($code)
                    && $code !== ''
                    && is_string($name)
                    && $name !== '',
                ARRAY_FILTER_USE_BOTH,
            )
            : [];
    }

    /**
     * Whether there is anything to switch between.
     *
     * One locale is the same as none: the switcher would offer the language
     * already being read.
     */
    public function hasLocaleSwitcher(): bool
    {
        return count($this->getLocales()) > 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSidebar(): array
    {
        return [
            'collapsible' => $this->sidebarCollapsible,
            'defaultOpen' => $this->sidebarDefaultOpen,
            'variant' => $this->sidebarVariant,
            'appearance' => $this->sidebarAppearance,
            'width' => $this->sidebarWidth,
            'collapsedWidth' => $this->collapsedSidebarWidth,
            'component' => $this->sidebarComponent,
        ];
    }

    /**
     * What the shell draws and what it leaves out.
     *
     * @return array<string, mixed>
     */
    public function getShell(): array
    {
        return [
            'navigation' => $this->navigation,
            'topbar' => $this->topbar,
            'breadcrumbs' => $this->breadcrumbs,
            'topbarComponent' => $this->topbarComponent,
            'userMenuItems' => $this->userMenuItems,
        ];
    }

    public function getMaxContentWidth(): ?string
    {
        return $this->maxContentWidth;
    }

    /**
     * @return class-string<Page>
     */
    public function getDashboard(): string
    {
        return $this->dashboard;
    }

    public function getRouteNamePrefix(): string
    {
        return "panel.{$this->getId()}.";
    }

    public function routeName(string $name): string
    {
        return $this->getRouteNamePrefix().$name;
    }

    /**
     * Whether this user may enter the panel.
     *
     * Two questions, and both must agree. The panel's own predicate is a rule
     * about *this panel* — "this one is for administrators" — and
     * `PanelUser::canAccessPanel()` is a rule about *the user*: a suspended
     * account, one belonging to no tenant. A panel that says yes cannot
     * overrule a user model that says no, and neither can quietly loosen the
     * other.
     *
     * A user model that implements neither is refused nothing, which is what
     * every panel written before the contract existed already assumed.
     */
    public function isAccessibleTo(?Authenticatable $user): bool
    {
        if ($user instanceof PanelUser && ! $user->canAccessPanel($this)) {
            return false;
        }

        return $this->canAccess === null || ($this->canAccess)($user);
    }

    /**
     * The panel props shared with Inertia. Nothing here may leak a closure,
     * middleware internals, or discovery paths.
     *
     * @return array{id: string, name: string, path: string, brandName: string, brandLogo: string|null, darkBrandLogo: string|null, icon: string|null, darkIcon: string|null, favicon: string|null, darkFavicon: string|null, darkMode: bool, maxContentWidth: string|null, unsavedChangesAlerts: bool, prefetch: 'hover'|'mount'|'click'|null, errorNotifications: array<int, array{title: string, body: string|null}|null>, renderHooks: array<string, list<array{component: string, data: array<string, mixed>, scopes: list<string>}>>, sidebar: array<string, mixed>, shell: array<string, mixed>, theme: array{light: array<string, string>, dark: array<string, string>}, cssHooks: array<string, string>}
     */
    public function toSharedArray(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'path' => $this->getPath(),
            'brandName' => $this->getBrandName(),
            'brandLogo' => $this->getBrandLogo(),
            'darkBrandLogo' => $this->getDarkBrandLogo(),
            'icon' => $this->getIcon(),
            'darkIcon' => $this->getDarkIcon(),
            'favicon' => $this->getFavicon(),
            'darkFavicon' => $this->getDarkFavicon(),
            'darkMode' => $this->hasDarkMode(),
            'maxContentWidth' => $this->getMaxContentWidth(),
            // Only the settings the frontend acts on cross the wire.
            // Transactions, strict authorization, and boot callbacks are
            // server concerns and stay here.
            'unsavedChangesAlerts' => $this->hasUnsavedChangesAlerts(),
            'prefetch' => $this->getPrefetch(),
            'errorNotifications' => $this->getErrorNotifications(),
            'renderHooks' => $this->getRenderHooks(),
            'sidebar' => $this->getSidebar(),
            'shell' => $this->getShell(),
            'theme' => $this->getTheme(),
            'cssHooks' => $this->getCssHooks(),
        ];
    }

    /**
     * @param  list<class-string>  $current
     * @param  array<array-key, class-string>  $incoming
     * @return list<class-string>
     */
    private function mergeClasses(array $current, array $incoming): array
    {
        return array_values(array_unique(array_merge($current, array_values($incoming))));
    }

    /**
     * @param  list<string>  $current
     * @param  array<array-key, string>  $incoming
     * @return list<string>
     */
    private function mergePaths(array $current, array $incoming): array
    {
        return array_values(array_unique(array_merge($current, array_values($incoming))));
    }
}
