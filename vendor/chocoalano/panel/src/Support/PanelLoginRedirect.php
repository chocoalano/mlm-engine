<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PandaPanel\Core\PanelManager;

/**
 * Where a guest is sent when they open a panel URL.
 *
 * The panel's own login when it has one, and the application's otherwise —
 * which is what every panel written before panel-level auth existed did.
 *
 * Wired in `bootstrap/app.php` rather than in a service provider, because the
 * framework registers its own default inside `withMiddleware()`'s
 * `afterResolving` hook: anything a provider sets is overwritten when the
 * HTTP kernel resolves. `withMiddleware()` is the supported place to say
 * this, and it is the last word.
 */
final class PanelLoginRedirect
{
    public static function for(Request $request): ?string
    {
        $panel = app(PanelManager::class)->resolveFromRequest($request);

        if ($panel === null || ! $panel->hasLogin()) {
            return Route::has('login') ? route('login') : null;
        }

        $login = $panel->routeName('auth.login');

        // The route only exists once the panel registered it; without this a
        // panel whose login was turned off after boot would redirect to
        // nothing.
        return Route::has($login) ? route($login) : null;
    }
}
