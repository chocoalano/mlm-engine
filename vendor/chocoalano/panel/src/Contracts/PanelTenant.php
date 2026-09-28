<?php

declare(strict_types=1);

namespace PandaPanel\Contracts;

/**
 * A model that can be the tenant a panel request is scoped to.
 *
 * Deliberately two methods and no more. Everything a tenant *is* — a team, an
 * organisation, a customer account, a database — is the application's, and a
 * framework that asked for a `plan` or a `logo` would be describing one
 * project's tenant rather than the idea of one.
 *
 * What the panel actually needs is what it can never guess: which value
 * identifies this tenant, and what to call it on screen. Both have obvious
 * defaults — the primary key and a `name` column — and both are wrong often
 * enough that guessing them silently is worse than asking.
 *
 * Optional in the same way `PanelUser` is optional. A panel with no tenancy
 * never asks, and a tenant model that does not implement this can still be
 * used as long as it has a key and a `name`. See `Tenancy::describe()`.
 */
interface PanelTenant
{
    /**
     * The value this tenant is identified by.
     *
     * Usually the primary key. A project routing on `acme` rather than on
     * `41` returns the slug here, and `Tenancy` looks tenants up by whatever
     * this returns.
     */
    public function getTenantKey(): int|string;

    /**
     * What to call this tenant on screen — in the switcher, in the shell, in
     * the sentence that says which tenant a record belongs to.
     */
    public function getTenantName(): string;
}
