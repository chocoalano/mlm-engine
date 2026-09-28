<?php

declare(strict_types=1);

namespace PandaPanel\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;

/**
 * What the notification centre needs a user model to be.
 *
 * Exactly Laravel's own `Notifiable`, named. Nothing has to implement it: the
 * trait already provides every method, and the controller accepts a model that
 * merely uses the trait.
 *
 * It exists to be written down. "The user model must be `Notifiable`" is a
 * requirement the panel has either way; an interface makes it one static
 * analysis can see, rather than one discovered when the bell throws.
 *
 * No native return types, deliberately: the trait declares none, and a model
 * using it would be an incompatible declaration against an interface that did.
 *
 * @phpstan-require-extends Model
 */
interface PanelNotifiable
{
    /**
     * @return MorphMany<DatabaseNotification, Model>
     */
    public function notifications();

    /**
     * @return MorphMany<DatabaseNotification, Model>
     */
    public function unreadNotifications();

    /**
     * @param  mixed  $instance
     * @return void
     */
    public function notify($instance);
}
