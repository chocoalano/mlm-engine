<?php

declare(strict_types=1);

namespace PandaPanel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PandaPanel\Core\PanelManager;
use PandaPanel\Tenancy\Tenancy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the tenant every query in this request will be scoped to.
 *
 * Registered by the route registrar for any panel that declared
 * `tenant()`, and never for one that did not — so a panel without tenancy
 * pays nothing and a panel with it cannot forget.
 *
 * Three things happen here and all three must succeed before anything is
 * queried:
 *
 * 1. **Identification.** The panel's own resolver says which tenant this
 *    request is for. `stancl/tenancy` has already switched the connection by
 *    the time this runs, if that is the arrangement; a project routing
 *    tenants on a path segment reads the route here. The framework never
 *    guesses.
 * 2. **Authorization.** `HasPanelTenants::canAccessPanelTenant()` is asked,
 *    directly, on every request. Not searched for in the switcher's list —
 *    that list is built for a dropdown, and a security answer must not change
 *    when a display decision does.
 * 3. **Binding.** Only then, and once.
 *
 * A tenant that cannot be identified is a 404 rather than a redirect: the
 * request named something that does not exist. A tenant the user may not
 * enter is a 403, and deliberately not a 404 — hiding *which* tenants exist
 * from somebody who already had to name one is security theatre that costs a
 * comprehensible error message.
 *
 * Position matters. This runs after `ResolvePanel`, because it asks the panel
 * for its resolver, and before every controller, because a controller that
 * queried first would have queried unscoped.
 */
final class ResolveTenant
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $panelId): Response
    {
        $panel = app(PanelManager::class)->get($panelId);

        if (! $panel->hasTenancy()) {
            return $next($request);
        }

        $user = $request->user();

        $tenant = $panel->resolveTenant($request, $user);

        abort_if($tenant === null, 404, __('panda-panel::errors.no_such_tenant'));
        abort_unless(Tenancy::allows($user, $tenant, $panel), 403);

        Tenancy::bind($tenant);

        return $next($request);
    }
}
