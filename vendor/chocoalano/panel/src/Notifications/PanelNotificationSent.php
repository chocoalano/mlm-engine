<?php

declare(strict_types=1);

namespace PandaPanel\Notifications;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use PandaPanel\Broadcasting\PanelNotification;

/**
 * A notification pushed to one user's open panels.
 *
 * The same channel and the same event name the toast already uses, because to
 * the frontend they are the same thing arriving: a message to show and, when
 * it was persisted, a bell to increment. Two events would mean two
 * subscriptions and two chances for them to disagree.
 *
 * The channel is the one `routes/channels.php` already authorizes, so a user
 * can only ever receive their own.
 */
final class PanelNotificationSent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly Authenticatable $user,
        public readonly array $payload,
    ) {
        // See `PanelNotification`: resolved while the sender's tenant is
        // still bound, not in whatever context the queue runs it.
        $this->channel = PanelNotification::channelFor($user);
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

    public function broadcastAs(): string
    {
        return 'panel.notification';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            ...$this->payload,
            // The toast reads `message`; the bell reads `title` and `body`.
            // Sent as both rather than making either side guess.
            'message' => $this->payload['title'] ?? '',
            // Present so the frontend knows whether the bell has a new row to
            // fetch, without asking.
            'persistent' => ($this->payload['persistent'] ?? false) === true,
        ];
    }
}
