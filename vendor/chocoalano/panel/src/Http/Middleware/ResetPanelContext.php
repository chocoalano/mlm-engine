<?php

declare(strict_types=1);

namespace PandaPanel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PandaPanel\Support\PanelContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clears the panel context at the start of every web request.
 *
 * `ResolvePanel` only runs inside panel route groups, so without this a
 * non-panel route would keep whatever the previous request left behind. In
 * a classic PHP request the container is rebuilt each time and the leak is
 * invisible; under Octane, or inside a test that issues several requests, it
 * is not. Enforcing the invariant here makes "no current panel outside a
 * panel" true in every environment rather than true by accident.
 */
final class ResetPanelContext
{
    public function __construct(private readonly PanelContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->forget();

        return $next($request);
    }
}
