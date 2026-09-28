<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PandaPanel\Core\PanelManager;
use PandaPanel\Http\Middleware\SetPanelLocale;

/**
 * Records which language this reader wants the panel in.
 *
 * One route, one session key. The redirect is `back()` rather than anywhere
 * in particular, because a language is changed *while reading something* and
 * being returned to a dashboard would lose the page it was changed on.
 *
 * The submitted code is checked against the panel's own list rather than
 * against the locales installed on the machine. `app()->setLocale()` accepts
 * any string, and an unchecked one would let a request write a directory
 * traversal into the session for the translator to try to load.
 */
final class PanelLocaleController
{
    public function __construct(private readonly PanelManager $manager) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $panel = $this->manager->currentPanel();

        abort_if($panel === null, 404, __('panda-panel::errors.no_panel'));

        $validated = $request->validate([
            'locale' => ['required', 'string'],
        ]);

        $locale = (string) $validated['locale'];

        abort_unless(
            array_key_exists($locale, $panel->getLocales()),
            422,
            __('panda-panel::errors.unknown_locale'),
        );

        $request->session()->put(SetPanelLocale::SESSION_KEY, $locale);

        return back();
    }
}
