<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Support\Facades\Config;

/**
 * Whether this application can actually broadcast to a browser.
 *
 * `Panel::broadcasting()` says whether a panel *wants* realtime notifications.
 * This says whether the application has anything to deliver them with, which
 * is a different question and one the panel was never asking.
 *
 * It has to be asked here rather than by the host, because the failure was
 * ugly and far from its cause: the server sent a channel name, the client
 * called `echo()` on it, `@laravel/echo-vue` threw "Echo has not been
 * configured" from inside `onMounted`, and the aborted mount produced a dozen
 * `Slot "default" invoked outside of the render function` warnings as Inertia
 * swapped a layout that had never finished mounting. Nothing in that sequence
 * names a broadcaster.
 *
 * Three things have to hold, and none of them is something the browser can
 * find out:
 *
 * 1. A default connection is named. A fresh Laravel with no
 *    `config/broadcasting.php` has none.
 * 2. That connection exists and has a driver. A named connection that was
 *    never defined is a typo, not a broadcaster.
 * 3. The driver reaches a browser. `null` is off by definition, and `log`
 *    writes to the application's log — real drivers, both of them, and
 *    neither is something Echo can subscribe to.
 *
 * Deliberately not checked: whether the credentials are correct. That is a
 * question only the broadcaster can answer, and an empty key is left to the
 * client guard rather than guessed at here — a panel that refused to connect
 * because it disliked the look of a key would be a worse failure than the one
 * this replaces.
 */
final class BroadcastSupport
{
    /**
     * Drivers that exist and are configured, but that no browser can attach
     * to.
     *
     * @var list<string>
     */
    private const UNREACHABLE_DRIVERS = ['null', 'log'];

    public static function isConfigured(): bool
    {
        $connection = Config::get('broadcasting.default');

        if (! is_string($connection) || $connection === '') {
            return false;
        }

        $driver = Config::get("broadcasting.connections.{$connection}.driver");

        if (! is_string($driver) || $driver === '') {
            return false;
        }

        return ! in_array($driver, self::UNREACHABLE_DRIVERS, true);
    }
}
