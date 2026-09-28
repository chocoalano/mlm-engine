<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Support\Format;
use PandaPanel\Tables\Enums\Alignment;
use PandaPanel\Tables\Enums\ColumnType;

final class NumberColumn extends Column
{
    private int $decimals = 0;

    private ?string $prefix = null;

    private ?string $suffix = null;

    protected Alignment $alignment = Alignment::End;

    public function type(): ColumnType
    {
        return ColumnType::Number;
    }

    public function decimals(int $decimals): self
    {
        $this->decimals = $decimals;

        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function suffix(string $suffix): self
    {
        $this->suffix = $suffix;

        return $this;
    }

    /**
     * Formatting happens here, not in Vue, so the locale rules live in one
     * place and the frontend renders a finished string.
     *
     * @return array{display: string, raw: float|int}|null
     */
    public function toCell(Model $record): ?array
    {
        $value = $this->resolveValue($record);

        if (! is_numeric($value)) {
            return null;
        }

        $numeric = $value + 0;

        return [
            'display' => sprintf(
                '%s%s%s',
                $this->prefix ?? '',
                Format::number((float) $numeric, $this->decimals),
                $this->suffix ?? '',
            ),
            'raw' => $numeric,
        ];
    }
}
