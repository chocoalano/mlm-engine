<?php

declare(strict_types=1);

namespace PandaPanel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PandaPanel\Auth\EmailCodeFactor;
use PandaPanel\Core\PanelManager;
use PandaPanel\Http\Controllers\PanelTwoFactorController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds every page until this session has answered an emailed code.
 *
 * Only for an account that turned the factor on. It works the way password
 * confirmation does: the session carries a mark, and this refuses everything
 * until it is there — so a new session on a new device is challenged even
 * though the password was right.
 *
 * The challenge route is exempt, or there would be nowhere to answer it.
 */
final class RequireEmailCode
{
    public function __construct(private readonly PanelManager $manager) {}

    public function handle(Request $request, Closure $next, ?string $panelId = null): Response
    {
        $panel = $panelId === null
            ? $this->manager->currentPanel()
            : $this->manager->get($panelId);

        $user = $request->user();

        if ($panel === null || $user === null) {
            return $next($request);
        }

        if (! EmailCodeFactor::isEnabledFor($user)) {
            return $next($request);
        }

        if ($request->session()->has(PanelTwoFactorController::SESSION_KEY)) {
            return $next($request);
        }

        $challenge = $panel->routeName('auth.two-factor.challenge');

        // A panel with no challenge route has nowhere to send them, and
        // refusing every page would lock the account out of a panel it is
        // entitled to.
        if (! Route::has($challenge)) {
            return $next($request);
        }

        // The challenge and its own two endpoints, or answering it would be
        // refused by the thing being answered.
        if ($request->routeIs($panel->routeName('auth.two-factor.*'))) {
            return $next($request);
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route($challenge);
    }
}
