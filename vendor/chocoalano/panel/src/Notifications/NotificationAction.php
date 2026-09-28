<?php

declare(strict_types=1);

namespace PandaPanel\Notifications;

use Illuminate\Support\Str;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Support\Label;
use PandaPanel\Support\SafeUrl;

/**
 * A button on a notification.
 *
 * A URL and nothing else. This is deliberately *not* an `Action`: an action is
 * resolved against the schema that declared it, and a notification has no
 * schema — it is a row in a table that may still be there next week, long
 * after the page that sent it stopped existing.
 *
 * So what crosses is a link the server produced, exactly as a link action's
 * URL is. Whatever the link points at authorizes for itself when it is
 * followed, which is the only check that can still be trusted a week later.
 */
final class NotificationAction
{
    private ?string $label = null;

    private ?string $url = null;

    private ActionVariant $variant = ActionVariant::Outline;

    private bool $markAsRead = true;

    private bool $newTab = false;

    public function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function url(string $url, bool $newTab = false): self
    {
        $this->url = SafeUrl::sanitize($url);
        $this->newTab = $newTab;

        return $this;
    }

    public function variant(ActionVariant $variant): self
    {
        $this->variant = $variant;

        return $this;
    }

    /**
     * Whether following this marks the notification read.
     *
     * On by default: a notification you acted on is one you have seen, and
     * leaving it unread would mean the count outlives the thing it counted.
     */
    public function markAsRead(bool $mark = true): self
    {
        $this->markAsRead = $mark;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array{name: string, label: string, url: string|null, variant: string, markAsRead: bool, newTab: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label ?? Label::resolve(
                'actions',
                $this->name,
                fn (): string => Str::headline($this->name),
            ),
            'url' => $this->url,
            'variant' => $this->variant->value,
            'markAsRead' => $this->markAsRead,
            'newTab' => $this->newTab,
        ];
    }

    /**
     * Rebuilds one from a stored row.
     *
     * A persisted notification is JSON in a database, so what comes back is
     * untrusted in exactly the way a request body is — a shape that does not
     * match is dropped rather than rendered.
     *
     * @param  array<string, mixed>  $data
     * @return array{name: string, label: string, url: string|null, variant: string, markAsRead: bool, newTab: bool}|null
     */
    public static function fromArray(array $data): ?array
    {
        $name = $data['name'] ?? null;
        $label = $data['label'] ?? null;
        $url = $data['url'] ?? null;
        $variant = $data['variant'] ?? null;

        if (! is_string($name) || ! is_string($label)) {
            return null;
        }

        $resolved = is_string($variant)
            ? ActionVariant::tryFrom($variant)
            : null;

        return [
            'name' => $name,
            'label' => $label,
            'url' => is_string($url) && $url !== '' ? SafeUrl::sanitize($url) : null,
            'variant' => ($resolved ?? ActionVariant::Outline)->value,
            'markAsRead' => ($data['markAsRead'] ?? true) === true,
            'newTab' => ($data['newTab'] ?? false) === true,
        ];
    }
}
