<?php

declare(strict_types=1);

namespace PandaPanel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PandaPanel\Support\PanelHomeRedirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands the starter kit's dashboard over to the panel.
 *
 * Middleware on the `web` group rather than a route this package registers:
 * `/dashboard` belongs to the application's own route file, and a package
 * that competed for the same URI would be relying on registration order to
 * win. Here the application keeps its route, its name, and anything else
 * bound to it — a request that reaches it is simply answered earlier.
 *
 * GET only, and never for a request that wants JSON. A starter kit's
 * dashboard is a screen; an application that has hung an API or a form post
 * off the same path keeps it.
 *
 * See `PanelHomeRedirect` for which requests this affects at all, and
 * `panda-panel.home_redirect` for turning it off.
 */
final class RedirectPanelHome
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || $request->expectsJson()) {
            return $next($request);
        }

        $target = PanelHomeRedirect::for($request);

        // A 302 rather than an Inertia location visit: the destination is a
        // page in this application, so an Inertia request follows it and
        // renders the panel without a full reload.
        return $target === null
            ? $next($request)
            : redirect()->to($target);
    }
}
