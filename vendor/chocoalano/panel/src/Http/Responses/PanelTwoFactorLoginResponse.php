<?php

declare(strict_types=1);

namespace PandaPanel\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same landing, for a sign-in that had a second factor in the middle.
 *
 * A separate response in Fortify because it is a separate controller, and it
 * needs the same treatment for the same reason: the panel the sign-in started
 * at is still the panel it should finish at, and the code challenge happened
 * on Fortify's own screen in between.
 */
final class PanelTwoFactorLoginResponse extends PanelRedirectResponse implements TwoFactorLoginResponse
{
    protected function redirect(): string
    {
        return 'login';
    }

    protected function json(): Response
    {
        return new JsonResponse('', 204);
    }
}
