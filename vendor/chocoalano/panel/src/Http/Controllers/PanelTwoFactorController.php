<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PandaPanel\Auth\EmailCodeChallenge;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Notifications\TwoFactorCode;

/**
 * The emailed-code second factor: enrolling in it, and answering it.
 *
 * A session-level challenge rather than part of the login POST. Fortify owns
 * signing in — its rate limiting, its passkeys, its TOTP — and reaching into
 * that pipeline to add a channel would mean owning a fork of it. This works
 * the way password confirmation does instead: the session is marked once the
 * code is answered, and the panel's own middleware holds every page until it
 * is.
 *
 * The mark is a timestamp in the session, so it dies with the session. A
 * second factor that survived signing out would not be one.
 */
final class PanelTwoFactorController
{
    /**
     * What the session remembers once the code has been answered.
     */
    public const SESSION_KEY = 'panel.mfa.email.confirmed_at';

    public function __construct(
        private readonly PanelManager $manager,
        private readonly EmailCodeChallenge $challenge,
    ) {}

    /**
     * The challenge page, sending a code if none is outstanding.
     */
    public function challenge(Request $request): Response
    {
        $user = $request->user();

        abort_if($user === null, 403);

        if (! $this->challenge->pending($user)) {
            $this->send($request);
        }

        return Inertia::render('panel/auth/EmailCode', [
            'panel' => $this->panel()->toSharedArray(),
            // The address, obscured. Enough to recognise which inbox to open
            // and not enough to learn one from somebody else's screen.
            'sentTo' => $this->obscure((string) $user->getAttribute('email')),
            'retryAfter' => $this->challenge->secondsUntilNextSend($user),
        ]);
    }

    /**
     * Sends another code.
     */
    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $code = $this->challenge->issue($user);

        // Null means the account has asked for too many. Said plainly rather
        // than pretending one was sent: a user waiting for an email that is
        // not coming will ask for five more.
        if ($code === null) {
            throw ValidationException::withMessages([
                'code' => __('panda-panel::notifications.two_factor.throttled'),
            ]);
        }

        // Emailing the code needs the user model to be `Notifiable`, which is
        // Laravel's own trait and what a starter kit ships. A model without it
        // cannot receive one, and saying so is better than a fatal.
        abort_unless(method_exists($user, 'notify'), 500, __('panda-panel::errors.not_notifiable'));

        $user->notify(new TwoFactorCode($code));

        return back()->with('success', __('panda-panel::notifications.two_factor.sent'));
    }

    /**
     * Answers the challenge.
     */
    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        if (! $this->challenge->verify($user, (string) $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => __('panda-panel::notifications.two_factor.invalid'),
            ]);
        }

        // Regenerated first: the session is about to be marked as having
        // passed a second factor, and a fixed id is the one thing that would
        // let somebody else inherit that.
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, now()->timestamp);

        return redirect()->intended(route($this->panel()->routeName('dashboard'), absolute: false));
    }

    /**
     * Turns the factor on for this account.
     *
     * The route is behind `RequirePassword`, so this is already somebody who
     * has just proved they are the account holder.
     */
    public function enable(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $user->forceFill(['two_factor_email_confirmed_at' => now()])->save();

        // Confirmed for this session too: making somebody answer a code the
        // moment they turned the feature on would be asking them to prove
        // something they just proved with their password.
        $request->session()->put(self::SESSION_KEY, now()->timestamp);

        return back()->with('success', __('panda-panel::notifications.two_factor.enabled'));
    }

    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $user->forceFill(['two_factor_email_confirmed_at' => null])->save();

        $this->challenge->forget($user);
        $request->session()->forget(self::SESSION_KEY);

        return back()->with('success', __('panda-panel::notifications.two_factor.disabled'));
    }

    /**
     * `gr***@example.test` — enough to recognise, not enough to learn.
     */
    private function obscure(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = mb_substr($name, 0, min(2, mb_strlen($name)));

        return $visible.str_repeat('*', max(mb_strlen($name) - 2, 1)).'@'.$domain;
    }

    private function panel(): Panel
    {
        return $this->manager->currentPanel()
            ?? throw PanelRegistrationException::noCurrentPanel();
    }
}
