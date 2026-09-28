<?php

declare(strict_types=1);

namespace PandaPanel\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Core\Panel;

/**
 * A user model that knows which tenants it belongs to.
 *
 * Two questions, and the difference between them is the same one that runs
 * through the rest of this framework's authorization: a list is what to
 * *offer*, and a check is what to *allow*. The switcher is built from the
 * first; every request is checked against the second. A user who is offered
 * three tenants and permitted two has a bug, and the bug is in the model —
 * which is exactly where a framework cannot fix it, and why both are asked
 * rather than one derived from the other.
 *
 * Required only for a panel that has declared a tenant model. Without it, a
 * tenant-scoped panel has no way to know whether the tenant in this request
 * is one this user may enter, and `ResolveTenant` refuses every request
 * rather than guessing — which is the correct failure, and a loud one.
 */
interface HasPanelTenants
{
    /**
     * Every tenant this user may enter through this panel.
     *
     * Used to build the switcher and to pick a default when a request names
     * no tenant. An empty collection is a legitimate answer — a user who
     * belongs to nothing gets a panel that refuses, rather than one that
     * shows everything.
     *
     * @return Collection<int, Model>
     */
    public function getPanelTenants(Panel $panel): Collection;

    /**
     * Whether this user may enter this particular tenant through this panel.
     *
     * Asked on every request, before anything is queried. Never derived from
     * `getPanelTenants()`: that would make the security boundary a function
     * of a list built for a dropdown, and a dropdown that grows for
     * performance reasons would quietly grow this too.
     */
    public function canAccessPanelTenant(Model $tenant, Panel $panel): bool;
}
