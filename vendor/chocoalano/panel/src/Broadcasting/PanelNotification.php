<?php

declare(strict_types=1);

namespace PandaPanel\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A toast pushed to one user's open panels.
 *
 * The payload is the shape the frontend already shows for flash messages, so
 * a job that finishes ten minutes after the request that started it reaches
 * the user the same way an immediate response would.
 *
 * The channel is the one `routes/channels.php` already authorizes, so a user
 * can only ever receive their own.
 */
final class PanelNotification implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  'success'|'info'|'warning'|'error'  $type
     * @param  string|null  $url  something to open, for a job whose result is a file
     */
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $message,
        public readonly string $type = 'info',
        public readonly ?string $url = null,
        public readonly ?string $urlLabel = null,
    ) {
        // Captured now rather than when the event is broadcast. A queued
        // broadcast runs in a worker where the tenant that dispatched it is
        // no longer bound, and resolving there would name the wrong channel —
        // or the central one — and the notification would simply never
        // arrive. Here, the tenant context is the one that sent it.
        $this->channel = self::channelFor($user);
    }

    /** The channel this notification was addressed to when it was created. */
    private readonly string $channel;

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    /**
     * The name both sides agree on. Built here rather than written out in Vue
     * so the two cannot drift.
     */
    public static function channelFor(Authenticatable $user): string
    {
        return NotificationChannel::for($user);
    }

    public function broadcastAs(): string
    {
        return 'panel.notification';
    }

    /**
     * @return array{type: string, message: string, url: string|null, urlLabel: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'message' => $this->message,
            // A finished export is a file, and a toast that only says so is
            // a toast that makes somebody go looking for it.
            'url' => $this->url,
            'urlLabel' => $this->urlLabel,
        ];
    }
}
