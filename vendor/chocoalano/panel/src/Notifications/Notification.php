<?php

declare(strict_types=1);

namespace PandaPanel\Notifications;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use PandaPanel\Notifications\Enums\NotificationColor;
use PandaPanel\Support\Label;

/**
 * One notification, and the three places it can go.
 *
 * A toast is transient — it appears and it is gone, and if nobody was looking
 * it never happened. That is right for "saved" and wrong for "your export of
 * 12,000 records finished", which is why this exists: a notification can be
 * persisted, counted, read later, and acted on.
 *
 * The three are not exclusive. `->send($user)` broadcasts *and* persists when
 * the notification asked to be persistent, so a user with the panel open sees
 * it immediately and a user who was away finds it waiting.
 *
 * Nothing executable crosses in any of them. An action is a label and a URL
 * the server produced; whatever that URL points at authorizes for itself when
 * it is followed, which is the only check still meaningful a week later.
 */
final class Notification
{
    private ?string $title = null;

    private ?string $body = null;

    private ?string $icon = null;

    private NotificationColor $color = NotificationColor::Info;

    /** @var list<NotificationAction> */
    private array $actions = [];

    private bool $persistent = false;

    private bool $broadcast = true;

    public function __construct(private readonly string $name) {}

    public static function make(?string $name = null): self
    {
        return new self($name ?? (string) Str::uuid());
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function color(NotificationColor $color): self
    {
        $this->color = $color;

        return $this;
    }

    /** Shorthands, because these four are most of what anybody writes. */
    public function success(): self
    {
        return $this->color(NotificationColor::Success);
    }

    public function info(): self
    {
        return $this->color(NotificationColor::Info);
    }

    public function warning(): self
    {
        return $this->color(NotificationColor::Warning);
    }

    public function danger(): self
    {
        return $this->color(NotificationColor::Danger);
    }

    /**
     * @param  array<array-key, NotificationAction>  $actions
     */
    public function actions(array $actions): self
    {
        $this->actions = array_values($actions);

        return $this;
    }

    /**
     * Keeps the notification in the database, where it can be read later.
     *
     * Off by default: most notifications are the answer to something the user
     * just did, and a bell that fills up with "Saved." is a bell nobody reads.
     */
    public function persistent(bool $persistent = true): self
    {
        $this->persistent = $persistent;

        return $this;
    }

    /**
     * Whether it is also pushed to the user's open panels.
     *
     * Worth turning off for something that only makes sense once opened —
     * a long report, say — where a toast would be an interruption rather than
     * an answer.
     */
    public function broadcast(bool $broadcast = true): self
    {
        $this->broadcast = $broadcast;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function isBroadcast(): bool
    {
        return $this->broadcast;
    }

    /**
     * Delivers it.
     *
     * The order matters: persisted first, then broadcast. A user who clicks
     * the toast's action and lands on the notification centre must find the
     * row already there.
     */
    public function send(Authenticatable $user): self
    {
        // `notify()` rather than a type check: what matters is whether this
        // notifiable can be notified, and a user model without the trait
        // simply has nowhere to store one.
        if ($this->persistent && method_exists($user, 'notify')) {
            $user->notify(new PanelDatabaseNotification($this->toArray()));
        }

        if ($this->broadcast) {
            event(new PanelNotificationSent($user, $this->toArray()));
        }

        return $this;
    }

    /**
     * The payload both channels carry, and the shape stored in the database.
     *
     * One shape rather than two, so a notification looks the same whether it
     * arrived over a websocket or was read out of a table an hour later.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title ?? Label::resolve(
                'notifications',
                $this->name,
                fn (): string => Str::headline($this->name),
            ),
            'body' => $this->body,
            'color' => $this->color->value,
            'icon' => $this->icon ?? $this->color->icon(),
            'actions' => array_map(
                static fn (NotificationAction $action): array => $action->toArray(),
                $this->actions,
            ),
            // What the transient toast needs, resolved here so the frontend
            // maps a colour name to a channel rather than deciding what a
            // colour means.
            'type' => $this->color->toastType(),
            // Whether a row was written, so the bell knows to refetch rather
            // than guess.
            'persistent' => $this->persistent,
        ];
    }
}
