<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Components;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Infolists\Enums\EntryType;
use PandaPanel\Tables\Enums\BadgeColor;

final class BadgeEntry extends Entry
{
    /** @var array<string, BadgeColor> */
    private array $colors = [];

    private BadgeColor $default = BadgeColor::Neutral;

    public function type(): EntryType
    {
        return EntryType::Badge;
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
     * @return array{label: string, color: string}|null
     */
    public function toValue(Model $record): ?array
    {
        $value = $this->resolveValue($record);

        if ($value === null || $value === '') {
            return null;
        }

        $label = is_scalar($value) ? (string) $value : '';

        return [
            'label' => $label,
            // Resolved here so the frontend maps a colour name to a literal
            // class rather than deciding what a value means.
            'color' => ($this->colors[$label] ?? $this->default)->value,
        ];
    }
}
