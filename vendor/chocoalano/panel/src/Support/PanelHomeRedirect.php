<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PandaPanel\Core\PanelManager;

/**
 * Where a signed-in user is sent when they land on the application's own
 * dashboard.
 *
 * A starter kit ships `/dashboard` and points Fortify's post-login redirect
 * at it. Installing a panel does not change either, so the first thing a
 * developer sees after `panel:install` is the starter kit's empty placeholder
 * and a panel they have to reach by typing its URL. That is the single worst
 * moment in the install, and it is not something the application did wrong.
 *
 * The counterpart to `PanelLoginRedirect`: that one sends a guest *out* of a
 * panel to the right login, this one sends a signed-in user *into* the panel
 * they would have had to find. Both are redirects rather than edits — nothing
 * here rewrites a route file or overwrites a component the application owns.
 *
 * Answers null, and so changes nothing, for every request that is not one of
 * the configured paths, for a guest, for a user no panel will admit, and for
 * a request that is already inside a panel — the last of which is what stops
 * a panel mounted at `/dashboard` redirecting to itself forever.
 */
final class PanelHomeRedirect
{
    /**
     * @var list<string>
     */
    private const DEFAULT_PATHS = ['dashboard'];

    public static function for(Request $request): ?string
    {
        $paths = self::paths();

        if ($paths === [] || ! $request->is(...$paths)) {
            return null;
        }

        $manager = app(PanelManager::class);

        // A panel answers for its own URLs. Redirecting one of them here
        // would be a loop, and a panel is free to be mounted anywhere.
        if ($manager->resolveFromRequest($request) !== null) {
            return null;
        }

        $panel = $manager->firstAccessibleTo($request->user());

        if ($panel === null) {
            return null;
        }

        $route = $panel->routeName('dashboard');

        // Registered by the panel's own route group, which config can turn
        // off. Without the check this would redirect to a route name that
        // does not resolve.
        return Route::has($route) ? route($route, absolute: false) : null;
    }

    /**
     * The paths this takes over, or none when it is turned off.
     *
     * Patterns rather than literals — `$request->is()` — so an application
     * that keeps a section of starter kit screens can hand over all of them
     * at once.
     *
     * Public because `PanelPostLogin` has to ask the same question about a
     * *stored* URL rather than the current request: an intended URL pointing
     * at a screen this class hands over is an intended URL that would bounce,
     * or 404 outright in an application that has no such route.
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('panda-panel.home_redirect', []);

        if (($config['enabled'] ?? false) !== true) {
            return [];
        }

        /** @var list<string> $paths */
        $paths = array_values(array_filter(
            (array) ($config['paths'] ?? self::DEFAULT_PATHS),
            static fn (mixed $path): bool => is_string($path) && $path !== '',
        ));

        return $paths;
    }
}
