<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Enums;

enum SortDirection: string
{
    case Ascending = 'asc';
    case Descending = 'desc';

    /**
     * Anything unrecognised in the URL falls back to ascending rather than
     * reaching the query builder.
     */
    public static function fromRequest(mixed $value): self
    {
        return is_string($value)
            ? (self::tryFrom(strtolower($value)) ?? self::Ascending)
            : self::Ascending;
    }

    public function opposite(): self
    {
        return $this === self::Ascending ? self::Descending : self::Ascending;
    }
}
