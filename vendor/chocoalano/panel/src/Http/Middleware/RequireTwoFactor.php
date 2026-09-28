<?php

declare(strict_types=1);

namespace PandaPanel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PandaPanel\Auth\EmailCodeFactor;
use PandaPanel\Core\PanelManager;
use PandaPanel\Pages\Settings\SecuritySettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a user at the security page until they have set up a second factor.
 *
 * For a panel where two-factor is not optional. The enforcement has to live
 * here rather than on a page, because the point is the pages a user has *not*
 * reached: a check on each one would be a check somebody has to remember to
 * add to the next one.
 *
 * The security page itself is exempt, or there would be nowhere to go and do
 * the thing being demanded. So are the account settings pages beside it —
 * signing out is a legitimate answer to being asked for a second factor.
 */
final class RequireTwoFactor
{
    public function __construct(private readonly PanelManager $manager) {}

    public function handle(Request $request, Closure $next, ?string $panelId = null): Response
    {
        $panel = $panelId === null
            ? $this->manager->currentPanel()
            : $this->manager->get($panelId);

        if ($panel === null || ! $panel->requiresTwoFactor()) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null || $this->hasSecondFactor($user)) {
            return $next($request);
        }

        $security = $panel->routeName('pages.'.SecuritySettings::slug());

        // Nowhere to send them means the panel is misconfigured. Letting them
        // through would silently turn a required second factor into a notice.
        if (! Route::has($security)) {
            abort(403, __('panda-panel::errors.two_factor_page_missing'));
        }

        if ($request->routeIs($security) || $request->routeIs($panel->routeName('pages.*'))) {
            return $next($request);
        }

        return redirect()
            ->route($security)
            ->with('warning', __('panda-panel::notifications.two_factor_required'));
    }

    /**
     * Whether this account has a second factor at all.
     *
     * A passkey counts. It is a stronger second factor than a code, and a
     * panel that demanded an authenticator app from somebody already using a
     * hardware key would be demanding a downgrade.
     */
    private function hasSecondFactor(object $user): bool
    {
        if (method_exists($user, 'hasEnabledTwoFactorAuthentication')
            && $user->hasEnabledTwoFactorAuthentication()) {
            return true;
        }

        // An emailed code is the panel's own channel, and it is a second
        // factor: demanding an authenticator app from somebody already using
        // one would be demanding a second second factor.
        if (EmailCodeFactor::isEnabledFor($user)) {
            return true;
        }

        return method_exists($user, 'passkeys') && $user->passkeys()->exists();
    }
}
