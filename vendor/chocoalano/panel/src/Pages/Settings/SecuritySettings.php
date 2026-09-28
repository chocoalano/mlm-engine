<?php

declare(strict_types=1);

namespace PandaPanel\Pages\Settings;

use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\Features;
use Laravel\Fortify\InteractsWithTwoFactorState;
use PandaPanel\Auth\EmailCodeFactor;
use PandaPanel\Pages\Page;
use PandaPanel\Support\PasswordRules;

/**
 * Password, two-factor, and passkey management inside the panel.
 *
 * `RequirePassword` is on the route rather than in `canAccess()`: a stale
 * session must be sent to the confirmation screen, and `canAccess()` can
 * only answer yes or no, which would turn a re-confirmation into a 403.
 */
final class SecuritySettings extends Page
{
    /**
     * Fortify's state repair, which clears a two-factor setup the user
     * abandoned half-way. The trait needs only `user()` and `session()`, so
     * the page supplies those rather than the framework reaching for an
     * application FormRequest.
     */
    use InteractsWithTwoFactorState;

    protected static ?string $slug = 'settings-security';

    protected static string $component = 'panel/settings/Security';

    protected static ?string $navigationIcon = 'shield';

    protected static int $navigationSort = 20;

    /*
     * Title, subheading and navigation group are read through methods rather
     * than set as static properties, because a property default is evaluated
     * before the translator can answer and would freeze this page's English
     * into every locale.
     */
    public static function title(): string
    {
        return __('panda-panel::pages.security.title');
    }

    public static function subheading(): string
    {
        return __('panda-panel::pages.security.subheading');
    }

    public static function navigationGroup(): string
    {
        return __('panda-panel::pages.navigation_group.account');
    }

    /** @var list<string> */
    protected static array $middleware = [RequirePassword::class];

    public static function routePath(): string
    {
        return 'settings/security';
    }

    /**
     * Mirrors the props the application's `SecurityController` builds, so the
     * two-factor and passkey components need no panel-specific branch.
     *
     * @return array<string, mixed>
     */
    public function props(): array
    {
        $user = $this->user();

        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys() ? $this->passkeys() : [],
            'passwordRules' => PasswordRules::attribute(),
            // The panel's own second factor, for somebody who will not
            // install an authenticator app — weaker than TOTP and much
            // stronger than nothing, which is the choice it competes with.
            'emailCodeEnabled' => EmailCodeFactor::isEnabledFor($user),
            'emailCodeUrls' => [
                'enable' => route($this->panel()->routeName('auth.two-factor.enable'), absolute: false),
                'disable' => route($this->panel()->routeName('auth.two-factor.disable'), absolute: false),
            ],
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $this->ensureStateIsValid();

            // `TwoFactorAuthenticatable` is a trait rather than an interface,
            // so the question is asked of the object rather than of its type.
            // A user model without it simply has two-factor off.
            $props['twoFactorEnabled'] = $user !== null
                && method_exists($user, 'hasEnabledTwoFactorAuthentication')
                && $user->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return $props;
    }

    public function user(): ?Authenticatable
    {
        return Auth::user();
    }

    public function session(): Session
    {
        return request()->session();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function passkeys(): array
    {
        $user = $this->user();

        if ($user === null) {
            return [];
        }

        if (! $user instanceof PasskeyUser) {
            return [];
        }

        return $user->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(static fn ($passkey): array => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->values()
            ->all();
    }
}
