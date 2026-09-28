<?php

declare(strict_types=1);

namespace PandaPanel\Widgets;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a widget on a resource page is allowed to know about that page.
 *
 * A record when the page has one, and the resource's own query when it is a
 * list — the same query the table ran, so a widget counting rows counts what
 * the user is actually looking at rather than the whole table.
 *
 * The query arrives as a closure and the count is memoized: a page with four
 * widgets that never mention the count runs no extra query at all, and one
 * where three of them do runs it once.
 */
final class PageContext
{
    private ?int $count = null;

    /**
     * @param  (Closure(): Builder<covariant Model>)|null  $query
     */
    public function __construct(
        private readonly ?Model $record = null,
        private readonly ?Closure $query = null,
    ) {}

    public static function forRecord(Model $record): self
    {
        return new self(record: $record);
    }

    /**
     * @param  Closure(): Builder<covariant Model>  $query
     */
    public static function forQuery(Closure $query): self
    {
        return new self(query: $query);
    }

    public function record(): ?Model
    {
        return $this->record;
    }

    /**
     * @return Builder<covariant Model>|null
     */
    public function query(): ?Builder
    {
        return $this->query === null ? null : ($this->query)();
    }

    /**
     * The number of records the page is showing, or zero when it shows none.
     */
    public function count(): int
    {
        return $this->count ??= $this->query()?->count() ?? 0;
    }
}
