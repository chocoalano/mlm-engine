<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Exports;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Support\Label;

/**
 * One column of an export.
 *
 * Deliberately not a table column. A table column knows how to sort, search,
 * and render HTML; an export column turns a record into one string. Reusing
 * the table's would mean a badge's colour and an icon's registry key ending
 * up in a spreadsheet, which is not what either was for.
 *
 * Dot notation reads through relations, so `author.name` is a column rather
 * than a reason to write a formatter.
 */
final class ExportColumn
{
    private ?string $label = null;

    /** @var (Closure(mixed, Model): mixed)|null */
    private ?Closure $formatUsing = null;

    private bool $enabledByDefault = true;

    public function __construct(private readonly string $name)
    {
        if (trim($name) === '') {
            throw PanelSchemaException::emptyName('export column');
        }
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @param  Closure(mixed, Model): mixed  $callback
     */
    public function formatUsing(Closure $callback): self
    {
        $this->formatUsing = $callback;

        return $this;
    }

    /**
     * Offered but unticked, for a column most exports do not want — an
     * internal note, a large blob, a column that is only occasionally the
     * point.
     */
    public function enabledByDefault(bool $enabled = true): self
    {
        $this->enabledByDefault = $enabled;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label ?? Label::resolve(
            'fields',
            $this->name,
            fn (): string => Str::headline(str_replace('.', ' ', $this->name)),
        );
    }

    public function isEnabledByDefault(): bool
    {
        return $this->enabledByDefault;
    }

    /**
     * The cell, as the string it will be written as.
     *
     * Everything becomes a string here rather than in the writer, so the two
     * file formats cannot disagree about what a boolean or a date looks like.
     */
    public function toCell(Model $record): string
    {
        $value = data_get($record, $this->name);

        if ($this->formatUsing !== null) {
            $value = ($this->formatUsing)($value, $record);
        }

        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_scalar($value) => (string) $value,
            // An array or an object has no obvious single-cell form, and JSON
            // is at least reversible.
            default => (string) json_encode($value),
        };
    }
}
