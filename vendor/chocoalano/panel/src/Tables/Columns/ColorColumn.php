<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Enums\ColumnType;

/**
 * A stored colour, shown as a swatch.
 *
 * The value is validated as a CSS colour on the server before it is sent.
 * That matters more here than for most columns: the value ends up in an
 * inline `background-color`, and an unvalidated string there is a way to put
 * arbitrary CSS on the page from a database row.
 */
final class ColorColumn extends Column
{
    private bool $copyable = false;

    public function type(): ColumnType
    {
        return ColumnType::Color;
    }

    /**
     * Offers the value as text beside the swatch, for a colour somebody needs
     * to read rather than only recognise.
     */
    public function copyable(bool $copyable = true): self
    {
        $this->copyable = $copyable;

        return $this;
    }

    /**
     * @return array{color: string, label: string}|null
     */
    public function toCell(Model $record): ?array
    {
        $value = $this->resolveValue($record);

        if (! is_string($value) || ! self::isColor($value)) {
            return null;
        }

        return ['color' => $value, 'label' => $value];
    }

    /**
     * Hex, `rgb()`, and `hsl()` only.
     *
     * A whitelist rather than a sanitizer: the set of CSS colour syntaxes
     * that are safe inline is small and knowable, and everything outside it
     * renders nothing rather than being repaired into something plausible.
     */
    private static function isColor(string $value): bool
    {
        return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) === 1
            || preg_match('/^rgba?\(\s*[\d.\s,%\/]+\)$/i', $value) === 1
            || preg_match('/^hsla?\(\s*[\d.\s,%\/deg]+\)$/i', $value) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return ['copyable' => $this->copyable];
    }
}
