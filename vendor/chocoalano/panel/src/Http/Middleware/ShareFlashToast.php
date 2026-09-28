<?php

declare(strict_types=1);

namespace PandaPanel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bridges Laravel's conventional flash keys onto the project's single toast
 * channel.
 *
 * `redirect()->with('success', '...')` is what Laravel code already writes,
 * and the frontend already listens on `flash.toast` through
 * `resources/js/lib/flashToast.ts`. This maps one onto the other so there is
 * exactly one toast mechanism rather than two competing ones.
 *
 * An explicit `Inertia::flash('toast', ...)` still wins: it is never
 * overwritten here.
 */
final class ShareFlashToast
{
    /**
     * Ordered by severity, so an error is surfaced ahead of a success when a
     * request somehow flashes both.
     *
     * @var list<string>
     */
    private const TYPES = ['error', 'warning', 'success', 'info'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();

        if (isset(Inertia::getFlashed($request)['toast'])) {
            return $next($request);
        }

        foreach (self::TYPES as $type) {
            $message = $session->get($type);

            if (is_string($message) && $message !== '') {
                Inertia::flash('toast', [
                    'type' => $type,
                    'message' => $message,
                ]);

                break;
            }
        }

        return $next($request);
    }
}
