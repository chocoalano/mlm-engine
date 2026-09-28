<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Support\Format;
use PandaPanel\Tables\Enums\FilterType;
use Throwable;

final class DateFilter extends Filter
{
    /**
     * How a bound is spelled in the chip. Day-month-year with a short month
     * name because `01/02` is a different date either side of the Atlantic
     * and a chip is read at a glance — `formats.date_compact`, so a locale
     * that writes it differently says so in one place
     */
    public function type(): FilterType
    {
        return FilterType::Date;
    }

    /**
     * Accepts `{from?: string, to?: string}` with ISO dates.
     *
     * Each bound is parsed strictly and dropped if it is not a real date, so
     * a malformed range narrows to whatever part of it was valid instead of
     * failing the whole request.
     *
     * @return array{from: CarbonImmutable|null, to: CarbonImmutable|null}|null
     */
    public function sanitize(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $from = $this->parse($value['from'] ?? null);
        $to = $this->parse($value['to'] ?? null);

        if ($from === null && $to === null) {
            return null;
        }

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return ['from' => $from, 'to' => $to];
    }

    protected function constrain(Builder $query, mixed $value): void
    {
        $column = $this->getColumn();

        if ($value['from'] instanceof CarbonImmutable) {
            $query->where($column, '>=', $value['from']->startOfDay());
        }

        if ($value['to'] instanceof CarbonImmutable) {
            $query->where($column, '<=', $value['to']->endOfDay());
        }
    }

    /**
     * The chip says the range in words.
     *
     * Without this the inherited `describe()` met an array, found it was not
     * scalar, and returned an empty string — so the chip read `Created At: `
     * and named a filter while saying nothing about what it was doing. A
     * one-sided range is the common case and reads as a bound rather than as
     * a range with a missing half.
     */
    protected function describe(mixed $value): string
    {
        $from = is_array($value) && $value['from'] instanceof CarbonImmutable
            ? $value['from']->format(Format::dateCompact())
            : null;

        $to = is_array($value) && $value['to'] instanceof CarbonImmutable
            ? $value['to']->format(Format::dateCompact())
            : null;

        return match (true) {
            $from !== null && $to !== null => $from.' – '.$to,
            $from !== null => 'from '.$from,
            $to !== null => 'until '.$to,
            default => '',
        };
    }

    private function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', substr($value, 0, 10)) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
