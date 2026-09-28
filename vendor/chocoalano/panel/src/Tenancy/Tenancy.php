<?php

declare(strict_types=1);

namespace PandaPanel\Tenancy;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Contracts\HasPanelTenants;
use PandaPanel\Contracts\PanelTenant;
use PandaPanel\Core\Panel;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Support\PanelContext;

/**
 * The tenant this request is scoped to.
 *
 * Held in `PanelContext` rather than in a static, so it lives exactly as long
 * as the request does and cannot leak between requests, between tests, or
 * between two requests inside one Octane worker — the same reason the current
 * panel and the current parent record live there.
 *
 * ## What this is, and what it deliberately is not
 *
 * It is **not** a multi-tenancy implementation. It does not create databases,
 * switch connections, partition a cache, or decide what a subdomain means.
 * Those are `stancl/tenancy`'s job and it is very good at them —
 * `docs/tenancy/stancl-tenancy.md` is the guide for putting the two together.
 *
 * What this *is* is the missing half: a stable, tested answer to "which
 * tenant is this request for", so that the panel can scope to it without
 * every project re-deriving the answer inside an overridden `Resource::query()`.
 * Before this existed, a tenant scope was a convention documented in prose,
 * and a resource that forgot to apply it looked exactly like one that had
 * nothing to scope.
 *
 * ## Where it comes from
 *
 * `ResolveTenant` binds it, once, at the front of a tenant-scoped panel's
 * middleware stack. Three things happen there and all three must succeed: the
 * tenant is identified, the user is checked against it, and it is bound. Every
 * reader after that is reading a decision already made.
 *
 * Identification is the application's, through `Panel::tenant()`'s resolver.
 * A project on `stancl/tenancy` returns `tenant()`; a project routing tenants
 * on a path segment reads the route; a project with one tenant per user
 * returns that user's. The framework never guesses, because every one of
 * those is right somewhere and wrong everywhere else.
 */
final class Tenancy
{
    private const KEY = 'panel.tenant';

    /**
     * Binds the tenant for this request.
     *
     * Only `ResolveTenant` and tests should call this. A binding made
     * anywhere else is a scope that took effect halfway through a request,
     * with everything before it already queried unscoped.
     */
    public static function bind(Model $tenant): void
    {
        app(PanelContext::class)->set(self::KEY, $tenant);
    }

    public static function current(): ?Model
    {
        $tenant = app(PanelContext::class)->get(self::KEY);

        return $tenant instanceof Model ? $tenant : null;
    }

    /**
     * The tenant, or a loud failure.
     *
     * A tenant-scoped resource with no tenant bound is a route registered
     * without `ResolveTenant`, not a state to degrade through: answering an
     * unscoped query would show every tenant's records to whoever asked.
     *
     * @throws PanelRegistrationException
     */
    public static function require(): Model
    {
        return self::current() ?? throw PanelRegistrationException::noCurrentTenant();
    }

    /**
     * The current tenant's key, or null when there is no tenancy.
     *
     * What a `where` clause needs, and the only thing most callers want.
     */
    public static function key(): int|string|null
    {
        $tenant = self::current();

        return $tenant === null ? null : self::keyOf($tenant);
    }

    /**
     * The value a tenant is identified by.
     *
     * The contract's answer when the model implements it, and the primary key
     * otherwise — because a model with a key is already identifiable, and
     * requiring an interface to state the obvious would make tenancy opt-in
     * twice.
     */
    public static function keyOf(Model $tenant): int|string
    {
        if ($tenant instanceof PanelTenant) {
            return $tenant->getTenantKey();
        }

        $key = $tenant->getKey();

        return is_int($key) || is_string($key) ? $key : (string) $key;
    }

    /**
     * What to call a tenant on screen.
     *
     * The contract, then a `name` attribute, then the key. Falling through to
     * the key rather than to an empty string on purpose: a switcher with a
     * blank row is a switcher nobody can use, and `41` at least identifies
     * which one it is.
     */
    public static function nameOf(Model $tenant): string
    {
        if ($tenant instanceof PanelTenant) {
            return $tenant->getTenantName();
        }

        $name = $tenant->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : (string) self::keyOf($tenant);
    }

    /**
     * One tenant, as the frontend receives it.
     *
     * @return array{key: int|string, name: string}
     */
    public static function describe(Model $tenant): array
    {
        return [
            'key' => self::keyOf($tenant),
            'name' => self::nameOf($tenant),
        ];
    }

    /**
     * Every tenant this user may enter through this panel.
     *
     * The switcher's list, and the pool a default is chosen from. A user
     * model that does not implement `HasPanelTenants` belongs to nothing as
     * far as this panel is concerned — which is what makes a tenant-scoped
     * panel refuse rather than fall open.
     *
     * @return list<Model>
     */
    public static function availableTo(?Authenticatable $user, Panel $panel): array
    {
        if (! $user instanceof HasPanelTenants) {
            return [];
        }

        return array_values($user->getPanelTenants($panel)->all());
    }

    /**
     * Whether this user may enter this tenant through this panel.
     *
     * Asked directly rather than by searching `availableTo()`: the list is
     * built for a dropdown and may be paginated, sorted, or trimmed, and a
     * security answer must not change when a display decision does.
     */
    public static function allows(?Authenticatable $user, Model $tenant, Panel $panel): bool
    {
        return $user instanceof HasPanelTenants
            && $user->canAccessPanelTenant($tenant, $panel);
    }

    /**
     * Runs a callback with a different tenant bound, then puts back whatever
     * was there.
     *
     * For the work that legitimately crosses the boundary: a console command
     * looping over tenants, a job re-entering the one it was queued from, a
     * test asserting that two tenants see different rows. Restoring in a
     * `finally` is the whole point — a callback that throws must not leave
     * the rest of the request scoped to somebody else's tenant.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function for(Model $tenant, callable $callback): mixed
    {
        $previous = self::current();

        self::bind($tenant);

        try {
            return $callback();
        } finally {
            $context = app(PanelContext::class);

            if ($previous === null) {
                $context->set(self::KEY, null);
            } else {
                $context->set(self::KEY, $previous);
            }
        }
    }
}
