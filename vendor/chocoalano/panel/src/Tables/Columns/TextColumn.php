<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Tables\Enums\ColumnType;

final class TextColumn extends Column
{
    private ?int $limit = null;

    private bool $wrap = false;

    public function type(): ColumnType
    {
        return ColumnType::Text;
    }

    /**
     * Truncates backend-side so the payload stays small, rather than sending
     * the full value and hiding it with CSS.
     */
    public function limit(int $characters): self
    {
        $this->limit = $characters;

        return $this;
    }

    public function wrap(bool $wrap = true): self
    {
        $this->wrap = $wrap;

        return $this;
    }

    public function toCell(Model $record): ?string
    {
        $value = $this->resolveValue($record);

        if ($value === null || $value === '') {
            return null;
        }

        $value = is_scalar($value) ? (string) $value : json_encode($value);

        if ($value === false) {
            return null;
        }

        return $this->limit === null ? $value : Str::limit($value, $this->limit);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'wrap' => $this->wrap,
        ];
    }
}
