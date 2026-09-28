<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Support\PanelPostLogin;
use PandaPanel\Support\PasswordRules;

/**
 * A panel's own front door.
 *
 * The pages are the application's existing auth screens, rendered inside the
 * panel so they carry its brand — and the forms post to Fortify's own
 * endpoints, which already exist and already work. That is the whole design:
 * one authentication implementation, presented per panel.
 *
 * Duplicating the login POST per panel would mean duplicating rate limiting,
 * two-factor, passkeys, and session fixation handling — four things that must
 * never disagree between two doors into the same application.
 */
final class PanelAuthController
{
    public function __construct(private readonly PanelManager $manager) {}

    public function login(Request $request): Response
    {
        // Which panel this sign-in started at. Fortify answers the POST, and
        // `/login` is not a panel URL — without this the response has nothing
        // to resolve a panel from and falls back to `fortify.home`. See
        // `PanelPostLogin`.
        PanelPostLogin::remember($request, $this->panel());

        return Inertia::render('panel/auth/Login', [
            'panel' => $this->panel()->toSharedArray(),
            'canResetPassword' => Features::enabled(Features::resetPasswords())
                && $this->panel()->hasPasswordReset(),
            'canRegister' => Features::enabled(Features::registration())
                && $this->panel()->hasRegistration(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function register(Request $request): Response
    {
        abort_unless($this->panel()->hasRegistration(), 404);

        PanelPostLogin::remember($request, $this->panel());

        return Inertia::render('panel/auth/Register', [
            'panel' => $this->panel()->toSharedArray(),
            'passwordRules' => PasswordRules::attribute(),
        ]);
    }

    public function requestPasswordReset(Request $request): Response
    {
        abort_unless($this->panel()->hasPasswordReset(), 404);

        PanelPostLogin::remember($request, $this->panel());

        return Inertia::render('panel/auth/ForgotPassword', [
            'panel' => $this->panel()->toSharedArray(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function resetPassword(Request $request): Response
    {
        abort_unless($this->panel()->hasPasswordReset(), 404);

        PanelPostLogin::remember($request, $this->panel());

        return Inertia::render('panel/auth/ResetPassword', [
            'panel' => $this->panel()->toSharedArray(),
            'email' => $request->query('email'),
            'token' => (string) $request->route('token'),
            'passwordRules' => PasswordRules::attribute(),
        ]);
    }

    public function verifyEmail(Request $request): Response
    {
        abort_unless($this->panel()->hasEmailVerification(), 404);

        return Inertia::render('panel/auth/VerifyEmail', [
            'panel' => $this->panel()->toSharedArray(),
            'status' => $request->session()->get('status'),
        ]);
    }

    private function panel(): Panel
    {
        return $this->manager->currentPanel()
            ?? throw PanelRegistrationException::noCurrentPanel();
    }
}
