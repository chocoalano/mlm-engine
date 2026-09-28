<?php

declare(strict_types=1);

namespace PandaPanel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PandaPanel\Core\PanelManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the current panel into the request-scoped context and enforces panel
 * access.
 *
 * Runs last in the panel middleware stack, after `auth` and `verified`, so
 * `$request->user()` is populated before `canAccess` is evaluated. Guests
 * never reach it on an authenticated panel; they are redirected earlier.
 *
 * A user who is signed in but not allowed into the panel gets a 403, never a
 * redirect. Hiding navigation is not an access control.
 */
final class ResolvePanel
{
    public function __construct(private readonly PanelManager $manager) {}

    public function handle(Request $request, Closure $next, ?string $panelId = null): Response
    {
        $panel = $panelId !== null
            ? $this->manager->get($panelId)
            : $this->manager->resolveFromRequest($request);

        if ($panel === null) {
            return $next($request);
        }

        $this->manager->setCurrentPanel($panel);

        abort_unless($panel->isAccessibleTo($request->user()), 403);

        // After the access check, never before: a user who is refused the
        // panel must not be able to trigger its boot work.
        $panel->boot();

        return $next($request);
    }
}
