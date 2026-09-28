<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Components;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Infolists\Enums\EntryType;
use PandaPanel\Tables\Enums\BadgeColor;

/**
 * A value shown as an icon rather than as its text.
 *
 * The icon is a registry key resolved at build time, never markup: an
 * unregistered name renders nothing rather than something arbitrary. The
 * label travels with it because an icon alone reads as empty to a screen
 * reader.
 */
final class IconEntry extends Entry
{
    /** @var array<string, string> */
    private array $icons = [];

    /** @var array<string, BadgeColor> */
    private array $colors = [];

    private BadgeColor $default = BadgeColor::Neutral;

    /** @var (Closure(mixed, Model): ?string)|null */
    private ?Closure $iconUsing = null;

    public function type(): EntryType
    {
        return EntryType::Icon;
    }

    /**
     * @param  array<string, string>  $icons  keyed by the value they stand for
     */
    public function icons(array $icons): self
    {
        $this->icons = $icons;

        return $this;
    }

    /**
     * @param  array<string, BadgeColor>  $colors
     */
    public function colors(array $colors, BadgeColor $default = BadgeColor::Neutral): self
    {
        $this->colors = $colors;
        $this->default = $default;

        return $this;
    }

    /**
     * For an icon that depends on more than the value itself.
     *
     * @param  Closure(mixed, Model): ?string  $callback
     */
    public function iconUsing(Closure $callback): self
    {
        $this->iconUsing = $callback;

        return $this;
    }

    /**
     * @return array{icon: string, color: string, label: string}|null
     */
    public function toValue(Model $record): ?array
    {
        $value = $this->resolveValue($record);

        $icon = $this->iconUsing !== null
            ? ($this->iconUsing)($value, $record)
            : ($this->icons[$this->key($value)] ?? null);

        if (! is_string($icon) || $icon === '') {
            return null;
        }

        return [
            'icon' => $icon,
            'color' => ($this->colors[$this->key($value)] ?? $this->default)->value,
            'label' => $this->key($value),
        ];
    }

    private function key(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
