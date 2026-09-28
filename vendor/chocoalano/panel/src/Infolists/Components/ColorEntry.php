<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Components;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\ColorPicker;
use PandaPanel\Infolists\Enums\EntryType;

/**
 * A stored colour shown as a swatch beside its value.
 *
 * Validated against the same pattern the colour field accepts, because this
 * value ends up inside a `style` attribute: a stored string that is a
 * stylesheet rather than a colour would otherwise be rendered as one.
 */
final class ColorEntry extends Entry
{
    private bool $copyable = false;

    public function type(): EntryType
    {
        return EntryType::Color;
    }

    /**
     * Offers the value for copying, which is most of what a colour is for.
     */
    public function copyable(bool $copyable = true): self
    {
        $this->copyable = $copyable;

        return $this;
    }

    public function toValue(Model $record): ?string
    {
        $value = $this->resolveValue($record);

        return is_string($value) && ColorPicker::isColor($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return ['copyable' => $this->copyable];
    }
}
