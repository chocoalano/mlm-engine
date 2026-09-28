<?php

declare(strict_types=1);

namespace PandaPanel\Contracts;

use PandaPanel\Core\Panel;

/**
 * A user model that decides for itself which panels it may enter.
 *
 * The other half of `Panel::canAccess()`. A closure on the panel is right for
 * a rule about *that panel* — "this one is for administrators" — and this is
 * right for a rule about *the user*: a suspended account, one whose email is
 * unverified, one belonging to no tenant. Written on the model, it applies to
 * every panel at once and cannot be forgotten when a new one is added.
 *
 * Both are asked, and both must agree. That is deliberate: a panel that says
 * yes cannot overrule a user model that says no, and neither can be quietly
 * loosened by the other.
 *
 * Optional. A user model that does not implement this is refused nothing —
 * the panel's own predicate is then the only question, which is what every
 * panel written before this contract existed already assumed.
 */
interface PanelUser
{
    /**
     * Whether this user may enter the panel at all.
     *
     * Asked on every request through the panel's middleware, before any page
     * is built, so a user who is refused never triggers the panel's boot work
     * — and gets a 403 rather than a redirect. Hiding navigation is never the
     * control.
     */
    public function canAccessPanel(Panel $panel): bool;
}
