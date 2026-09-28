<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Support\Format;
use PandaPanel\Tables\Enums\ColumnType;

class DateColumn extends Column
{
    /*
     * Null rather than a pattern, because the default is a fact about the
     * locale and a property initializer runs before the translator can be
     * asked. A column that calls `->format()` is never touched by this.
     */
    protected ?string $format = null;

    protected bool $relative = false;

    public function type(): ColumnType
    {
        return ColumnType::Date;
    }

    public function format(string $format): static
    {
        $this->format = $format;

        return $this;
    }

    /**
     * The pattern a column of this type uses when nothing said otherwise.
     *
     * A method rather than a property default, because the answer is a fact
     * about the locale and a property is initialized before the translator
     * can be asked — and a method is what `DateTimeColumn` overrides to ask
     * for a different one.
     */
    protected function defaultFormat(): string
    {
        return Format::date();
    }

    /**
     * Shows "3 days ago" as the display value. The ISO value is still sent,
     * so the frontend can show the exact timestamp on hover without a second
     * request.
     */
    public function relative(bool $relative = true): static
    {
        $this->relative = $relative;

        return $this;
    }

    /**
     * @return array{display: string, iso: string}|null
     */
    public function toCell(Model $record): ?array
    {
        $value = $this->resolveValue($record);

        if (! $value instanceof CarbonInterface) {
            return null;
        }

        return [
            'display' => $this->relative
                ? $value->diffForHumans()
                : $value->format($this->format ?? $this->defaultFormat()),
            'iso' => $value->toIso8601String(),
        ];
    }
}
