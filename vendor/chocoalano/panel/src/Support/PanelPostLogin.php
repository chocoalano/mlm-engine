<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;

/**
 * Where somebody who has just signed in actually ends up.
 *
 * The third redirect in the set, and the one that was missing. `PanelLoginRedirect`
 * sends a guest *to* the right login; `PanelHomeRedirect` sends a signed-in user
 * who lands on `/dashboard` *into* a panel. Neither covers the moment in
 * between, and that moment is the one everybody sees:
 *
 * A panel's login page posts to Fortify's own endpoint — deliberately, so that
 * rate limiting, two-factor, passkeys and session fixation have exactly one
 * implementation. Fortify then answers with its own `LoginResponse`, which
 * redirects to `fortify.home`. That is `/dashboard` in every Laravel starter
 * kit and in Fortify's own default config. So signing in *at* `/admin/login`
 * put you on the application's dashboard, and on a blank application — which
 * has no `/dashboard` route at all — on a 404.
 *
 * `PanelHomeRedirect` was supposed to catch that, and cannot in either of the
 * two cases that matter. It is `web` group middleware, so a `/dashboard` that
 * matches no route never reaches it. And it answers with the *first* panel the
 * user can enter, so signing in at the second panel's login page took you to
 * the first one.
 *
 * ## What this does instead
 *
 * The panel whose login page was rendered is remembered in the session, and
 * the response Fortify hands back redirects there. Nothing is guessed from the
 * URL: the login POST goes to `/login`, which is not a panel route and never
 * resolves to a panel.
 *
 * Three answers, in order:
 *
 * 1. The panel this sign-in started at, when the account can enter it.
 * 2. The first panel the account can enter, when the sign-in did not start at
 *    a panel — but only where `home_redirect` is on, because that flag is
 *    already the application saying it would rather land people in the panel
 *    than on its own dashboard.
 * 3. Null, and Fortify's own answer stands untouched.
 *
 * @see PanelLoginRedirect  the guest's way in
 * @see PanelHomeRedirect   the same handover, for a request already signed in
 */
final class PanelPostLogin
{
    /**
     * Which panel this sign-in started at.
     *
     * The session rather than a query parameter or a hidden field: the login
     * POST is Fortify's, and a panel that added a field to it would be asking
     * every application that wrote its own login form to add one too.
     */
    public const SESSION_KEY = 'panda-panel.login_panel';

    /**
     * Records that a panel's own auth page was rendered.
     *
     * Every one of them, not just the login: a password reset finishes by
     * signing the account in, and somebody who started at `/admin/forgot-password`
     * meant `/admin`.
     */
    public static function remember(Request $request, Panel $panel): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, $panel->getId());
    }

    /**
     * The URL to send a freshly-signed-in account to, or null to leave
     * Fortify's own answer alone.
     */
    public static function for(Request $request): ?string
    {
        if (config('panda-panel.login_redirect', true) !== true) {
            return null;
        }

        $panel = self::panel($request);

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
     * Drops an intended URL that points at a screen the panel has taken over.
     *
     * `redirect()->intended()` prefers whatever the guest was originally
     * reaching for, which is right — except when that URL is `/dashboard`,
     * because the application's own middleware asked for a guest redirect
     * *from* `/dashboard` and stored it on the way past. Following it means a
     * second redirect at best and, in an application with no such route, a
     * 404. The panel is where that request was going to end up anyway.
     */
    public static function discardHandedOverIntent(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $intended = $request->session()->get('url.intended');
        $paths = PanelHomeRedirect::paths();

        if (! is_string($intended) || $paths === []) {
            return;
        }

        $path = trim((string) (parse_url($intended, PHP_URL_PATH) ?: '/'), '/');

        if (Str::is($paths, $path === '' ? '/' : $path)) {
            $request->session()->forget('url.intended');
        }
    }

    /**
     * The panel this sign-in belongs to, if any.
     *
     * The stored id is forgotten whether or not it is used: it describes one
     * sign-in, and a stale one would send the *next* sign-in — from the
     * application's own login page, say — somewhere it was never asked to go.
     */
    private static function panel(Request $request): ?Panel
    {
        $manager = app(PanelManager::class);
        $user = $request->user();

        $id = $request->hasSession()
            ? $request->session()->pull(self::SESSION_KEY)
            : null;

        if (is_string($id) && $manager->has($id)) {
            $panel = $manager->get($id);

            // An account that can sign in but cannot enter this panel falls
            // through to the same search as anybody else, rather than being
            // sent to a URL that will bounce it straight back out.
            if ($panel->isAccessibleTo($user)) {
                return $panel;
            }
        }

        // The sign-in did not start at a panel. Handing it one is the same
        // decision `home_redirect` already describes, so it is the same flag.
        return PanelHomeRedirect::paths() === []
            ? null
            : $manager->firstAccessibleTo($user);
    }
}
