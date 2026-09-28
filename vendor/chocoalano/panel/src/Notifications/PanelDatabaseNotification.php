<?php

declare(strict_types=1);

namespace PandaPanel\Notifications;

use Illuminate\Notifications\Notification as LaravelNotification;

/**
 * The database half of a panel notification.
 *
 * Laravel's own notification class rather than a table of the panel's own, so
 * `unreadNotifications`, `markAsRead()`, and the rest all work without a line
 * of code — and a notification sent by anything else in this application
 * shows up in the panel's bell too.
 *
 * Broadcasting is not on `via()`. The panel pushes its own event carrying the
 * same payload, and the bell refetches when that event says a row was
 * written — one subscription, and no second event to disagree with the first.
 */
final class PanelDatabaseNotification extends LaravelNotification
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly array $payload) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
