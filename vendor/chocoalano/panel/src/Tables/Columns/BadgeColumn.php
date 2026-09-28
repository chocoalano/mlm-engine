<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Support\Label;
use PandaPanel\Tables\Enums\BadgeColor;
use PandaPanel\Tables\Enums\ColumnType;

final class BadgeColumn extends Column
{
    /** @var array<string, BadgeColor> */
    private array $colors = [];

    /** @var array<string, string> */
    private array $labels = [];

    public function type(): ColumnType
    {
        return ColumnType::Badge;
    }

    /**
     * Maps a value to a semantic colour. Unmapped values render neutral, so a
     * new enum case degrades to a plain badge rather than an unstyled one.
     *
     * @param  array<string, BadgeColor|string>  $colors
     */
    public function colors(array $colors): self
    {
        foreach ($colors as $value => $color) {
            $this->colors[(string) $value] = BadgeColor::fromValue($color);
        }

        return $this;
    }

    /**
     * @param  array<string, string>  $labels
     */
    public function labels(array $labels): self
    {
        $this->labels = $labels;

        return $this;
    }

    /**
     * @return array{value: string, label: string, color: string}|null
     */
    public function toCell(Model $record): ?array
    {
        $value = $this->resolveValue($record);

        if ($value === null) {
            return null;
        }

        $key = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \BackedEnum => (string) $value->value,
            is_scalar($value) => (string) $value,
            default => null,
        };

        if ($key === null) {
            return null;
        }

        return [
            'value' => $key,
            'label' => $this->labels[$key] ?? Label::resolve(
                'values',
                $key,
                fn (): string => Str::headline($key),
            ),
            'color' => ($this->colors[$key] ?? BadgeColor::Neutral)->value,
        ];
    }
}
