<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * The parent record a nested resource is scoped to, for this request.
 *
 * Held in `PanelContext` rather than in a static, so it lives exactly as long
 * as the request does and cannot leak between requests or between tests —
 * the same reason the current panel lives there.
 *
 * `ResolveParentRecord` binds it from the route, and `Resource::query()` reads
 * it. Nothing else should: a nested resource that reached for the parent
 * anywhere but its query would have a second place where the scope could be
 * forgotten.
 */
final class ParentRecord
{
    private const KEY = 'panel.parent-record';

    public static function bind(Model $record): void
    {
        app(PanelContext::class)->set(self::KEY, $record);
    }

    public static function current(): ?Model
    {
        $record = app(PanelContext::class)->get(self::KEY);

        return $record instanceof Model ? $record : null;
    }

    /**
     * The parent record, or a loud failure.
     *
     * A nested resource with no parent bound is a route that was registered
     * wrongly, not a state to degrade through: answering an unscoped query
     * would show every child of every parent.
     *
     * @param  class-string<PanelResource>  $resource
     *
     * @throws PanelRegistrationException
     */
    public static function require(string $resource): Model
    {
        return self::current() ?? throw PanelRegistrationException::noParentRecord($resource);
    }

    /**
     * The route parameter a nested resource's parent travels in.
     */
    public static function routeParameter(): string
    {
        return 'parentRecord';
    }

    /**
     * Resolves and authorizes the parent for a nested resource.
     *
     * Through the parent resource's own `query()`, so a parent outside that
     * resource's scope is a 404 here as well — a child cannot be reached
     * under a parent the user could not have opened.
     *
     * @param  class-string<PanelResource>  $parentResource
     */
    public static function resolve(string $parentResource, int|string $key): ?Model
    {
        $record = $parentResource::query()->find($key);

        if ($record === null || ! $parentResource::canView($record)) {
            return null;
        }

        return $record;
    }

    /**
     * Whether `$parent` is registered in the same panel as `$resource`.
     *
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<PanelResource>  $parent
     */
    public static function assertRegistered(string $resource, string $parent): void
    {
        $panel = panel();

        if ($panel === null) {
            return;
        }

        if (! app(PanelManager::class)->resources($panel)->contains($parent)) {
            throw PanelRegistrationException::unregisteredParentResource($resource, $parent);
        }
    }
}
