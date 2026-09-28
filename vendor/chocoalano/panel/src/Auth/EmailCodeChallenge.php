<?php

declare(strict_types=1);

namespace PandaPanel\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * A one-time code sent to the account's own address.
 *
 * The second factor for somebody who will not install an authenticator app —
 * weaker than TOTP and much stronger than nothing, which is the choice it
 * actually competes with.
 *
 * The code lives in the cache rather than a table. It is valid for ten
 * minutes; a row that outlives a cache flush would be a row somebody has to
 * remember to prune, and an expiry the storage enforces cannot be forgotten.
 *
 * It is stored hashed. A cache a support engineer can read is a cache that
 * can be read, and a code in it is a password for one login.
 *
 * Two rate limits, because they are two different attacks: how often a code
 * may be *sent* (a mailbox is not a place to be flooded) and how often one may
 * be *guessed* (six digits is a million tries at machine speed).
 */
final class EmailCodeChallenge
{
    /** How long a code is good for. */
    private const TTL_MINUTES = 10;

    /** Sends per hour, per user. */
    private const SEND_LIMIT = 5;

    /** Guesses per minute, per user. */
    private const ATTEMPT_LIMIT = 5;

    /**
     * Issues a code, or null when the account has asked for too many.
     *
     * Returns the plain code so the caller can send it; nothing else ever
     * sees it again.
     */
    public function issue(Authenticatable $user): ?string
    {
        if (RateLimiter::tooManyAttempts($this->sendKey($user), self::SEND_LIMIT)) {
            return null;
        }

        RateLimiter::hit($this->sendKey($user), 3600);

        // Six digits, zero-padded: a code somebody reads off a screen and
        // types on a phone. `random_int` because this is a credential.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->codeKey($user), Hash::make($code), now()->addMinutes(self::TTL_MINUTES));

        return $code;
    }

    /**
     * Whether this is the code, spending it if so.
     *
     * Single use: a correct code is forgotten the moment it is accepted, so a
     * code read over somebody's shoulder is worth nothing once used.
     */
    public function verify(Authenticatable $user, string $code): bool
    {
        if (RateLimiter::tooManyAttempts($this->attemptKey($user), self::ATTEMPT_LIMIT)) {
            return false;
        }

        RateLimiter::hit($this->attemptKey($user), 60);

        $hashed = Cache::get($this->codeKey($user));

        if (! is_string($hashed) || ! Hash::check($code, $hashed)) {
            return false;
        }

        Cache::forget($this->codeKey($user));
        RateLimiter::clear($this->attemptKey($user));

        return true;
    }

    /**
     * Whether a code is currently outstanding, so a page can say "we sent
     * one" rather than sending a second.
     */
    public function pending(Authenticatable $user): bool
    {
        return Cache::has($this->codeKey($user));
    }

    /**
     * Seconds until another code may be sent, or zero when one may be now.
     */
    public function secondsUntilNextSend(Authenticatable $user): int
    {
        return RateLimiter::tooManyAttempts($this->sendKey($user), self::SEND_LIMIT)
            ? RateLimiter::availableIn($this->sendKey($user))
            : 0;
    }

    /**
     * Throws away anything outstanding — on sign-out, or when the factor is
     * turned off.
     */
    public function forget(Authenticatable $user): void
    {
        Cache::forget($this->codeKey($user));
        RateLimiter::clear($this->attemptKey($user));
    }

    private function codeKey(Authenticatable $user): string
    {
        return 'panel.mfa.email.code.'.$this->identifier($user);
    }

    private function sendKey(Authenticatable $user): string
    {
        return 'panel.mfa.email.send.'.$this->identifier($user);
    }

    private function attemptKey(Authenticatable $user): string
    {
        return 'panel.mfa.email.attempt.'.$this->identifier($user);
    }

    private function identifier(Authenticatable $user): string
    {
        return Str::of((string) $user->getAuthIdentifier())->toString();
    }
}
