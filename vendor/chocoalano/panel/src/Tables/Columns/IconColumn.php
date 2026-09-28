<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Enums\Alignment;
use PandaPanel\Tables\Enums\BadgeColor;
use PandaPanel\Tables\Enums\ColumnType;

/**
 * A value shown as an icon.
 *
 * The value is mapped to an icon *name* on the server, and the name resolves
 * through the build-time icon registry like every other icon in the panel —
 * so a table can never ask the browser to fetch an icon that was not compiled
 * in, and `panel:icons` can find the names by reading the source.
 *
 * Colour is a `BadgeColor`, reusing the palette badges already map to literal
 * classes for, rather than a second colour vocabulary that would drift.
 */
final class IconColumn extends Column
{
    protected Alignment $alignment = Alignment::Center;

    /** @var array<array-key, string> */
    private array $icons = [];

    /** @var array<array-key, BadgeColor> */
    private array $colors = [];

    /** @var (Closure(mixed, Model): ?string)|null */
    private ?Closure $iconUsing = null;

    private ?string $falseIcon = null;

    private ?string $trueIcon = null;

    private bool $boolean = false;

    public function type(): ColumnType
    {
        return ColumnType::Icon;
    }

    /**
     * Maps each value to an icon registry key.
     *
     * @param  array<array-key, string>  $icons
     */
    public function icons(array $icons): self
    {
        $this->icons = $icons;

        return $this;
    }

    /**
     * @param  array<array-key, BadgeColor>  $colors
     */
    public function colors(array $colors): self
    {
        $this->colors = $colors;

        return $this;
    }

    /**
     * Resolves the icon on the server. The closure returns a registry key,
     * never markup or a path.
     *
     * @param  Closure(mixed, Model): ?string  $callback
     */
    public function iconUsing(Closure $callback): self
    {
        $this->iconUsing = $callback;

        return $this;
    }

    /**
     * The common case: a tick or a cross.
     */
    public function boolean(string $trueIcon = 'check', string $falseIcon = 'x'): self
    {
        $this->boolean = true;
        $this->trueIcon = $trueIcon;
        $this->falseIcon = $falseIcon;

        return $this;
    }

    /**
     * @return array{icon: string, color: string, label: string}|null
     */
    public function toCell(Model $record): ?array
    {
        $value = $this->resolveValue($record);
        $icon = $this->iconFor($value, $record);

        if ($icon === null) {
            return null;
        }

        return [
            'icon' => $icon,
            'color' => $this->colorFor($value)->value,
            // The accessible name for an icon that is the whole cell: without
            // it the column reads as empty to a screen reader.
            'label' => $this->labelFor($value),
        ];
    }

    private function iconFor(mixed $value, Model $record): ?string
    {
        if ($this->iconUsing !== null) {
            $icon = ($this->iconUsing)($value, $record);

            return is_string($icon) && $icon !== '' ? $icon : null;
        }

        if ($this->boolean) {
            return $value ? $this->trueIcon : $this->falseIcon;
        }

        $key = is_scalar($value) ? (string) $value : null;

        return $key !== null ? ($this->icons[$key] ?? null) : null;
    }

    private function colorFor(mixed $value): BadgeColor
    {
        if ($this->boolean) {
            return $value ? BadgeColor::Success : BadgeColor::Danger;
        }

        $key = is_scalar($value) ? (string) $value : null;

        return $key !== null ? ($this->colors[$key] ?? BadgeColor::Neutral) : BadgeColor::Neutral;
    }

    private function labelFor(mixed $value): string
    {
        if ($this->boolean) {
            return $value ? 'Yes' : 'No';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
