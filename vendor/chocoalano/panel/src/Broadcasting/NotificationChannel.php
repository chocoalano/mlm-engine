<?php

declare(strict_types=1);

namespace PandaPanel\Broadcasting;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Tenancy\Tenancy;
use RuntimeException;

/**
 * The one place a notification channel name is decided.
 *
 * Three parties have to agree on it and they run at different times: the
 * event that broadcasts, the shell prop the browser subscribes with, and the
 * callback that authorizes the subscription. A second copy of the format in
 * any of them is a silent mismatch — notifications that are sent and never
 * arrive, or a channel nobody is allowed to join.
 *
 * ## Why a user id is not enough
 *
 * `App.Models.User.1` identifies a row, and a row id is only unique inside
 * one database. An application with a database per tenant has a user 1 in
 * every one of them, and they are different people. Broadcasting is a single
 * shared namespace regardless — one Reverb, one Pusher app — so all of those
 * users landed on the same private channel, and whether tenant B's employee
 * saw tenant A's notification came down to which of them happened to be
 * connected.
 *
 * ## What this resolves to
 *
 * | Panel                                   | Channel |
 * | --------------------------------------- | ------- |
 * | no tenancy                              | `App.Models.User.{id}` — unchanged |
 * | tenancy, a tenant bound                 | `panel.tenant.{tenant}.user.{id}` |
 * | tenancy, no tenant bound (central page) | `panel.central.user.{id}` |
 * | `broadcastChannelUsing()` declared      | whatever it returns |
 *
 * A panel that declared no tenancy keeps exactly the name it had, because for
 * that application the id *is* unique and renaming it would break every
 * `routes/channels.php` in the wild for no gain.
 *
 * A panel that declared tenancy through this package gets isolation without
 * configuring anything, because the package can see the tenant itself. It
 * cannot see anybody else's: an application that identifies tenants through
 * another library must say so with `broadcastChannelUsing()`, and the
 * framework supplies the seam rather than pretending to guess.
 *
 * ## Central is its own namespace, not the absence of one
 *
 * A tenant panel serving a central page has no tenant, and the unsafe thing
 * to do there is fall back to the bare user id — which is the collision this
 * class exists to remove. It gets `panel.central.` instead: distinct from
 * every tenant, and distinct from the non-tenant default.
 */
final class NotificationChannel
{
    /**
     * The channel this user's notifications travel on.
     *
     * Resolved against the panel in hand, or the current one. Outside a panel
     * entirely — a queue worker that never bound one — the non-tenant default
     * applies, which is why events capture their channel when they are
     * constructed rather than when they are broadcast.
     */
    public static function for(Authenticatable $user, ?Panel $panel = null): string
    {
        $panel ??= app(PanelManager::class)->currentPanel();

        $resolver = $panel?->getBroadcastChannelResolver();

        if ($resolver instanceof Closure) {
            return self::validated($resolver($user), $panel);
        }

        if ($panel === null || ! $panel->hasTenancy()) {
            return 'App.Models.User.'.$user->getAuthIdentifier();
        }

        $tenant = Tenancy::current();

        return $tenant === null
            ? 'panel.central.user.'.$user->getAuthIdentifier()
            : 'panel.tenant.'.Tenancy::keyOf($tenant).'.user.'.$user->getAuthIdentifier();
    }

    /**
     * Whether this user may listen on this channel.
     *
     * Register it from `routes/channels.php` so the authorization and the
     * name come from the same place:
     *
     *     Broadcast::channel('{channel}', fn ($user, $channel) =>
     *         NotificationChannel::authorize($user, $channel));
     *
     * Compares against what this user's channel actually resolves to rather
     * than parsing the name, so a channel belonging to another tenant is
     * refused without anybody having to write a pattern that understands
     * tenancy.
     */
    public static function authorize(?Authenticatable $user, string $channel): bool
    {
        return $user !== null && self::for($user) === $channel;
    }

    /**
     * A resolver that answered with nothing is a configuration that meant to
     * isolate and did not.
     *
     * Refused rather than fallen back on: the fallback would be the bare user
     * id, which is the exact collision the resolver was declared to prevent.
     * Failing here is loud, happens on the first render, and is fixable;
     * falling back is silent and ships.
     */
    private static function validated(mixed $channel, ?Panel $panel): string
    {
        if (is_string($channel) && trim($channel) !== '') {
            return $channel;
        }

        throw new RuntimeException(sprintf(
            'The broadcast channel resolver for panel [%s] returned %s. It must return a '
                .'non-empty channel name. Falling back to the default would put every tenant '
                .'that shares a user id back on one channel, which is what the resolver was '
                .'declared to prevent.',
            $panel?->getId() ?? 'unknown',
            get_debug_type($channel),
        ));
    }
}
