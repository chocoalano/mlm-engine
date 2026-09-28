<?php

declare(strict_types=1);

namespace PandaPanel\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * For a custom resource page whose route carries `{record}`.
 *
 * Resolution goes through `Resource::query()` like every other lookup, so a
 * record outside the resource scope is a 404 rather than something a custom
 * page can reach around. Authorization is `canView()` by default, because a
 * page that shows a record the user may not view would be a leak whatever
 * else it does; a page needing a different ability overrides
 * `authorizeRecord()`.
 *
 * The resolved record is held for the request, so a page that reads it in
 * three places does not run three queries.
 */
trait InteractsWithRecord
{
    private ?Model $record = null;

    /**
     * Resolves, authorizes, and remembers the record. Call once at the top
     * of `render()`.
     *
     * A null key is a singular resource: its route carries no `{record}`
     * because there is nothing to choose between, so the resource resolves
     * its one record itself.
     */
    protected function resolveRecord(int|string|null $key = null): Model
    {
        $resource = static::$resource;

        $record = $key === null || $resource::isSingular()
            ? $resource::resolveSingularRecord()
            : $resource::resolveRecord($key);

        abort_unless($this->authorizeRecord($record), 403);

        return $this->record = $record;
    }

    /**
     * The record this page is working on.
     *
     * Throws rather than returning null: reaching for a record before
     * resolving one is a programming error, not a state to handle.
     */
    protected function getRecord(): Model
    {
        return $this->record ?? throw new \LogicException(
            static::class.' has no record yet. Call resolveRecord() in render() first.'
        );
    }

    protected function hasRecord(): bool
    {
        return $this->record !== null;
    }

    protected function authorizeRecord(Model $record): bool
    {
        return static::$resource::canView($record);
    }
}
