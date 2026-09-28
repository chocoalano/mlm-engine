<?php

declare(strict_types=1);

namespace PandaPanel\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registering at a panel's own sign-up page lands in that panel.
 *
 * A panel that turned registration on meant "people arrive here", and Fortify
 * would otherwise finish the account it just created on the application's
 * dashboard.
 */
final class PanelRegisterResponse extends PanelRedirectResponse implements RegisterResponse
{
    protected function redirect(): string
    {
        return 'register';
    }

    protected function json(): Response
    {
        return new JsonResponse('', 201);
    }
}
