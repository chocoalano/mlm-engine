<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use PandaPanel\Forms\Enums\FieldType;

/**
 * A colour, stored as text.
 *
 * Validated against the syntaxes that are safe to put in an inline style,
 * for the same reason `ColorColumn` is: the value ends up in a
 * `background-color`, and an unvalidated string there is arbitrary CSS from a
 * database row. The rule lives here rather than being left to the caller so
 * a colour cannot be stored that the table then refuses to show.
 */
final class ColorPicker extends Field
{
    private const HEX = '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i';

    private const RGB = '/^rgba?\(\s*[\d.\s,%\/]+\)$/i';

    private const HSL = '/^hsla?\(\s*[\d.\s,%\/deg]+\)$/i';

    /** @var list<string> */
    private array $swatches = [];

    public function type(): FieldType
    {
        return FieldType::ColorPicker;
    }

    /**
     * Colours offered as one-click choices beside the picker.
     *
     * @param  list<string>  $swatches
     */
    public function swatches(array $swatches): self
    {
        $this->swatches = array_values(array_filter($swatches, self::isColor(...)));

        return $this;
    }

    public static function isColor(string $value): bool
    {
        return preg_match(self::HEX, $value) === 1
            || preg_match(self::RGB, $value) === 1
            || preg_match(self::HSL, $value) === 1;
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        return ['string', 'regex:'.self::HEX];
    }

    protected function castForForm(mixed $value): ?string
    {
        return is_string($value) && self::isColor($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return ['swatches' => $this->swatches];
    }
}
