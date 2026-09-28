<?php

declare(strict_types=1);

namespace PandaPanel\Http\Responses;

use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;
use PandaPanel\Support\PanelPostLogin;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shared half of the three Fortify responses a panel replaces.
 *
 * Each of them is the same two lines in Fortify — a JSON body for an API
 * client, `redirect()->intended(Fortify::redirects(…))` for a browser — and
 * the only thing a panel changes is the fallback that `intended()` is handed.
 * Written once here so the three subclasses differ by exactly what actually
 * differs between them: the status code, and which `redirects` key is the
 * application's last word.
 *
 * The JSON branch is left alone in every case. A client that asked for JSON is
 * reading the status code, not following a redirect, and rewriting the body to
 * mention a panel would break somebody's SPA for no gain.
 *
 * @see PanelPostLogin for how the destination is chosen
 */
abstract class PanelRedirectResponse
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return $this->json();
        }

        // Before reading `intended`, because this is what removes the stored
        // URL when it points at a screen the panel has taken over.
        PanelPostLogin::discardHandedOverIntent($request);

        return redirect()->intended(
            PanelPostLogin::for($request) ?? Fortify::redirects($this->redirect()),
        );
    }

    /**
     * The `fortify.redirects.*` key this response falls back to when no panel
     * claims the sign-in.
     */
    abstract protected function redirect(): string;

    /**
     * Fortify's own answer for a client that asked for JSON, unchanged.
     */
    abstract protected function json(): Response;
}
