<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Components;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use PandaPanel\Infolists\Enums\EntryType;
use PandaPanel\Support\Format;

final class DateTimeEntry extends Entry
{
    /* Null for the same reason as `DateColumn::$format`. */
    private ?string $format = null;

    private bool $since = false;

    public function type(): EntryType
    {
        return EntryType::DateTime;
    }

    public function format(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    /**
     * Renders as "3 days ago". Computed on the server, so the string does not
     * drift while a page sits open — a refresh is what updates it.
     */
    public function since(bool $since = true): self
    {
        $this->since = $since;

        return $this;
    }

    public function toValue(Model $record): ?string
    {
        $value = $this->resolveValue($record);

        if ($value === null) {
            return null;
        }

        $date = $value instanceof DateTimeInterface ? Date::instance($value) : Date::parse((string) $value);

        return $this->since
            ? $date->diffForHumans()
            : $date->format($this->format ?? Format::dateTimeVerbose());
    }
}
