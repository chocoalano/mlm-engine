<?php

declare(strict_types=1);

namespace PandaPanel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\ParentRecord;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the parent record a nested resource is scoped to.
 *
 * The resource class arrives as a middleware parameter rather than from the
 * request, exactly as the panel id does for `ResolvePanel`: which resource a
 * route belongs to is a registration fact, and reading it from the URL would
 * make it something a request could choose.
 *
 * The parent is resolved through the *parent* resource's `query()` and
 * authorized with its `canView()`, so a parent the user could not have opened
 * is a 404 here too. Without that, `/users/9/posts` would be a way to read
 * user 9's children while `/users/9` itself was refused.
 */
final class ResolveParentRecord
{
    /**
     * @param  Closure(Request): Response  $next
     * @param  class-string<PanelResource>  $resource
     */
    public function handle(Request $request, Closure $next, string $resource): Response
    {
        $parentResource = $resource::parentResource();

        abort_if($parentResource === null, 404);

        // Route parameters are strings; anything else means the segment was
        // never matched, which is a route registered without one.
        $key = $request->route(ParentRecord::routeParameter());

        abort_unless(is_string($key), 404);

        $parent = ParentRecord::resolve($parentResource, $key);

        abort_if($parent === null, 404);

        ParentRecord::bind($parent);

        return $next($request);
    }
}
