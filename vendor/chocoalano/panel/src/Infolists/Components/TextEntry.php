<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Components;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Infolists\Enums\EntryType;

final class TextEntry extends Entry
{
    private ?int $limit = null;

    private bool $prose = false;

    public function type(): EntryType
    {
        return EntryType::Text;
    }

    /**
     * Truncates on the server so the payload stays small, rather than
     * sending the whole value and hiding it with CSS.
     */
    public function limit(int $characters): self
    {
        $this->limit = $characters;

        return $this;
    }

    /**
     * Renders as a paragraph that wraps, for a value that is a sentence
     * rather than a label.
     */
    public function prose(bool $prose = true): self
    {
        $this->prose = $prose;

        return $this;
    }

    public function toValue(Model $record): ?string
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
        return ['prose' => $this->prose];
    }
}
