<?php

declare(strict_types=1);

namespace PandaPanel\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing in at a panel's login page lands in that panel.
 *
 * Bound over Fortify's own `LoginResponse`, whose answer is
 * `fortify.home` — `/dashboard` in every starter kit, and a route that does
 * not exist at all in a blank application.
 */
final class PanelLoginResponse extends PanelRedirectResponse implements LoginResponse
{
    protected function redirect(): string
    {
        return 'login';
    }

    protected function json(): Response
    {
        return response()->json(['two_factor' => false]);
    }
}
