<?php

declare(strict_types=1);

namespace PandaPanel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PandaPanel\Core\PanelManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the request in the language its reader chose.
 *
 * The choice lives in the session under `SESSION_KEY`, written by
 * `PanelLocaleController`. The session rather than a column on the user: a
 * panel installs into somebody else's `users` table, and a package that
 * required a migration to let a reader change language would be asking for a
 * schema change to render a dropdown. An application that wants the choice to
 * follow an account across devices overrides this by setting the locale in
 * its own middleware — this only ever *narrows*, never insists.
 *
 * Nothing happens for a panel that offers no locales, which is the default:
 * `app.locale` already decides the language, and this exists so a reader can
 * disagree with it.
 *
 * A stored locale the current panel does not offer is ignored rather than
 * cleared. Two panels may offer different languages, and forgetting the
 * choice on the way through the narrower one would lose it for the panel that
 * did offer it.
 *
 * Position matters. It runs after `ResolvePanel`, because it asks the panel
 * what it offers, and before every controller — a controller that built a
 * schema first would have built it in the wrong language, and the labels are
 * resolved as the schema is built.
 */
final class SetPanelLocale
{
    /**
     * Where the choice is kept.
     *
     * Namespaced, because the session is the application's and a bare
     * `locale` key is one an application is entitled to be using already.
     */
    public const SESSION_KEY = 'panda-panel.locale';

    public function __construct(private readonly PanelManager $manager) {}

    public function handle(Request $request, Closure $next, ?string $panelId = null): Response
    {
        $panel = $panelId !== null && $this->manager->has($panelId)
            ? $this->manager->get($panelId)
            : $this->manager->currentPanel();

        if ($panel === null) {
            return $next($request);
        }

        $locales = $panel->getLocales();
        $chosen = $request->session()->get(self::SESSION_KEY);

        if (is_string($chosen) && array_key_exists($chosen, $locales)) {
            app()->setLocale($chosen);
        }

        return $next($request);
    }
}
