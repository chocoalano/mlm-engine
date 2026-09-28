<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Panels
    |--------------------------------------------------------------------------
    |
    | The panel providers to register, in order. Panels are listed explicitly
    | rather than discovered: registration order decides which panel a user is
    | sent to when the request does not name one, and adding a panel should be
    | a deliberate edit rather than a filesystem side effect.
    |
    | The classes *inside* a panel are discovered — see each provider's
    | discoverResources() / discoverPages() / discoverWidgets() calls.
    |
    | @var list<class-string<\PandaPanel\Core\PanelProvider>>
    |
    */

    'panels' => [
        // App\Panels\Admin\AdminPanelProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Panel routes are registered during boot, one group per panel, with the
    | path, domain and middleware each panel declares. Turn this off when the
    | application registers them itself — a test harness that boots panels
    | without HTTP, for example.
    |
    */

    'register_routes' => true,

    /*
    |--------------------------------------------------------------------------
    | Web middleware
    |--------------------------------------------------------------------------
    |
    | Two pieces of middleware belong to the whole `web` group rather than to
    | the panel route groups:
    |
    |   ResetPanelContext — clears the resolved panel at the start of every
    |   request, so nothing leaks between requests under Octane or between
    |   requests inside one test.
    |
    |   ShareFlashToast — maps Laravel's conventional flash keys onto the
    |   single toast channel the frontend listens on.
    |
    | Set this to false when you would rather register them yourself in
    | `bootstrap/app.php`, which is the right call if you need them at a
    | specific position in the stack.
    |
    */

    'register_web_middleware' => true,

    /*
    |--------------------------------------------------------------------------
    | Guest redirect
    |--------------------------------------------------------------------------
    |
    | Sends a guest who opens a panel URL to *that panel's* own login, when the
    | panel has one, and to the application's `login` route otherwise — which
    | is exactly what Laravel does by default, so turning this on adds a case
    | rather than replacing one.
    |
    | Set this to false if your application calls `redirectGuestsTo()` in
    | `bootstrap/app.php` itself. Yours would otherwise be overwritten. To keep
    | panel logins working alongside your own rule, call into it:
    |
    |   use PandaPanel\Support\PanelLoginRedirect;
    |
    |   ->withMiddleware(function (Middleware $middleware): void {
    |       $middleware->redirectGuestsTo(
    |           fn ($request) => PanelLoginRedirect::for($request) ?? route('welcome'),
    |       );
    |   })
    |
    */

    'register_guest_redirect' => true,

    /*
    |--------------------------------------------------------------------------
    | Home redirect
    |--------------------------------------------------------------------------
    |
    | The other half of the guest redirect. A Laravel Vue starter kit ships a
    | `/dashboard` route with an empty placeholder page, and points Fortify's
    | post-login redirect at it. Installing a panel changes neither, so the
    | first screen after signing in is that placeholder and the panel is
    | somewhere you have to know the URL of.
    |
    | With this on, a signed-in user who lands on one of these paths is sent to
    | the first panel they can enter instead. Nothing is rewritten to do it:
    | the application keeps its route, its route name, and its page component,
    | and turning this off gives all three back.
    |
    | The paths are `Request::is()` patterns, so `'reports/*'` hands over a
    | whole section. A path a panel itself is mounted on is ignored — the panel
    | answers for its own URLs, and redirecting one to itself is a loop.
    |
    | Set enabled to false for an application whose `/dashboard` is a real
    | screen it means to keep.
    |
    */

    'home_redirect' => [
        'enabled' => true,

        'paths' => ['dashboard'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Translations
    |--------------------------------------------------------------------------
    |
    | Where `vendor:publish --tag=panda-panel-translations` puts the panel's
    | strings, and where the panel reads your edits back from.
    |
    | With this on — the default — they publish into `lang/en`, `lang/id`, and
    | any other locale a release adds, beside the strings your own application
    | has written. The framework has no idea a namespaced translation can live
    | there, so the package wraps the translation loader to find it; see
    | `PandaPanel\Translation\PanelTranslationLoader`.
    |
    | The cost of the flat layout is a shared filename. `lang/en/actions.php`
    | may already be yours, and `formats.php`, `notifications.php` and
    | `integrations.php` are names an application is every bit as likely to
    | have chosen for itself.
    |
    | At runtime the two sets coexist: the loader merges key by key, and your
    | own non-namespaced lookups are untouched. Publishing is the part to know
    | about. A file of yours sitting at one of those names has no record in
    | `.panel-assets.json`, so `panel:assets` reports it as a conflict and
    | writes nothing — it cannot tell your file from an unrecorded copy of
    | ours. Resolve it once with `--reconciled=<path>` to keep yours, or
    | `--force` to take ours, and it is settled from then on.
    |
    | Before v0.5.4 that file was read as `new` and overwritten on the first
    | update after an upgrade. If you are upgrading from below that, check
    | `php artisan panel:assets` before running it with `--update`.
    |
    | Set this to false to go back to Laravel's own convention,
    | `lang/vendor/panda-panel/{locale}`, which keeps the panel's strings in a
    | directory of their own and needs no loader at all.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Login redirect
    |--------------------------------------------------------------------------
    |
    | The third redirect in the set, and the one everybody notices. A panel's
    | login page posts to Fortify's own endpoint — deliberately, so that rate
    | limiting, two-factor, passkeys and session handling have exactly one
    | implementation — and Fortify then redirects to `fortify.home`. That is
    | `/dashboard` in every starter kit and in Fortify's shipped config, so
    | signing in at `/admin/login` used to land on the application's dashboard,
    | and on a blank application on a 404.
    |
    | With this on, the panel whose login page was rendered is remembered for
    | the length of the sign-in, and the response lands there instead. A
    | sign-in that did not start at a panel is handed the first panel the
    | account can enter, but only where `home_redirect` is on — that flag is
    | already the application saying it would rather land people in the panel.
    |
    | An intended URL still wins, which is what makes `/admin/users` behind a
    | sign-in come back to `/admin/users`. The one exception is an intended URL
    | that `home_redirect` has taken over, because following it would bounce
    | straight back out again.
    |
    | Set this to false if your application binds Fortify's LoginResponse,
    | TwoFactorLoginResponse or RegisterResponse itself.
    |
    */

    'login_redirect' => true,

    'translations' => [
        'publish_to_lang_root' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | The package ships two migrations: Laravel's own `notifications` table
    | (which the notification centre reads on every panel request) and the
    | `two_factor_email_confirmed_at` column on `users`.
    |
    | They run from the package by default, because a panel cannot render
    | without the first — an install that had to remember a publish step would
    | 500 on its very first page. Both check before they touch anything, so an
    | application that already has the table or the column is untouched.
    |
    | Turn this off to own them yourself:
    |
    |   php artisan vendor:publish --tag=panda-panel-migrations
    |
    */

    'load_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    |
    | A resource with `integrations()->isEnabled(true)` gets a screen where an
    | administrator configures outbound HTTP requests fired on its writes. The
    | server issues those requests, which makes the screen a server-side
    | request forgery surface by construction — the destination is typed into
    | a form rather than written in code.
    |
    | Two gates, and a URL has to pass both.
    |
    | `allowed_hosts` is an allowlist of `Str::is()` patterns, and it is empty,
    | so nothing is reachable until a destination is added here. Deny by
    | default: a panel installed and left alone can call nowhere, and adding a
    | destination is a deploy rather than a form submission.
    |
    | `block_private_networks` refuses any host that resolves into the private,
    | loopback or link-local ranges — `169.254.169.254` above all, which is the
    | unauthenticated cloud metadata endpoint that hands out IAM credentials.
    | Checked when an integration is saved and again immediately before each
    | request, because a name approved last week can resolve elsewhere today.
    |
    | Leave the second on. It is what makes relaxing the first survivable.
    |
    */

    'integrations' => [

        'allowed_hosts' => [
            // 'api.example.com',
            // '*.partner.io',
        ],

        'block_private_networks' => true,

        /*
        | Delivery history.
        |
        | One row per attempt, which on a busy resource is a table that
        | outgrows the records it describes. So it is bounded twice, and both
        | bounds are applied immediately after each delivery rather than by
        | anything you have to schedule:
        |
        |   keep_per_integration — a hard cap. Only this many rows survive per
        |   integration, so the table is bounded at cap × integrations however
        |   much traffic there is. This is the bound that holds in an
        |   application with no scheduler at all.
        |
        |   retention_days — a window, so integrations that fire twice a year
        |   do not keep rows from three years ago. Set it to 0 to keep the cap
        |   and nothing else.
        |
        | Bodies are stored truncated. Headers never are: they hold the API
        | keys these requests carry, and a log of them would be a credential
        | store nobody meant to create.
        */

        'history' => [
            'enabled' => true,

            'keep_per_integration' => 50,

            'retention_days' => 30,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | The languages a reader may switch the panel between, as
    | `code => name in that language`.
    |
    | Empty by default, and empty means no switcher: an application that
    | serves one language should not grow a language menu in every panel
    | header because it upgraded. Setting one locale is the same as setting
    | none — there is nothing to switch to.
    |
    |   'locales' => [
    |       'en' => 'English',
    |       'id' => 'Bahasa Indonesia',
    |   ],
    |
    | This decides only the *switcher*. The panel already follows
    | `app()->getLocale()` however that was set, so an application with
    | `APP_LOCALE=id` and nothing here renders entirely in Indonesian.
    |
    | The names are written in their own language rather than translated,
    | because somebody looking for their language is looking for the word they
    | would use for it — a reader who cannot read the current locale cannot
    | read "Indonesian" in it either.
    |
    | The package ships `en` and `id`. Any other code needs
    | `lang/vendor/panda-panel/{code}/` — see the Translations guide. A code
    | listed here with no files falls back to `fallback_locale` rather than
    | rendering keys.
    |
    | A panel narrows this with `->locales([...])` when one panel serves a
    | different audience from another.
    |
    */

    'locales' => [
        // 'en' => 'English',
        // 'id' => 'Bahasa Indonesia',
    ],

    /*
    |--------------------------------------------------------------------------
    | Derived Labels
    |--------------------------------------------------------------------------
    |
    | Where the panel looks for your words before it derives its own.
    |
    | A column named `created_at` renders as "Created At" and a resource for a
    | `User` model as "User" — `Str::headline()`, and English. Before falling
    | back to it, the panel asks this file for a translation keyed by the name
    | it was about to headline:
    |
    |   // lang/id/panel.php
    |   return [
    |       'fields' => ['created_at' => 'Dibuat pada'],
    |       'resources' => ['User' => 'Pengguna'],
    |   ];
    |
    | One entry, and every column, field, entry, filter and export column of
    | that name follows it across every panel. The file is the application's,
    | because these are the application's words — this package translates only
    | what this package wrote.
    |
    | Nothing is required. With no such file the behaviour is what it always
    | was: `->label()` where it is set, `Str::headline()` everywhere else.
    | `->label()` is still checked first, so one table that needs a different
    | word says so without changing anything here.
    |
    | `file` is the name of that file under `lang/{locale}/`, without the
    | extension. Change it if `panel` is a name your application already uses.
    |
    */

    'labels' => [
        'file' => 'panel',
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend
    |--------------------------------------------------------------------------
    |
    | Where `vendor:publish --tag=panda-panel-assets` puts the panel's Vue
    | components, and where the generators write the components they scaffold.
    | Both are relative to the application's `resources/` directory, because
    | every component registry in the frontend is an `import.meta.glob` over
    | these paths — a build-time allowlist by design.
    |
    */

    'frontend' => [
        'panel_path' => 'js/panel',
        'pages_path' => 'js/pages/Panels',
    ],

    /*
    |--------------------------------------------------------------------------
    | Forms
    |--------------------------------------------------------------------------
    |
    | What to do about a schema that contradicts itself — two fields writing to
    | one state path, or a field that is required on a page where it is
    | disabled and so never submitted. Both produce a form that looks correct
    | and behaves inexplicably.
    |
    |   'throw'  — refuse to compile the schema.
    |   'log'    — write a warning and carry on.
    |   'ignore' — say nothing.
    |
    | Null means `throw` in `local` and `testing` and `log` everywhere else:
    | the message is useful where a developer is looking, and an exception
    | costs more than the bug where a user is.
    |
    | `currency` is what a MoneyInput uses when it does not name one: an ISO
    | 4217 code such as 'IDR' or 'USD'. An application that deals in one
    | currency sets it here once rather than on every field.
    |
    */

    'forms' => [
        'diagnostics' => env('PANDA_PANEL_FORM_DIAGNOSTICS'),
        'currency' => env('PANDA_PANEL_CURRENCY', 'USD'),
    ],

];
