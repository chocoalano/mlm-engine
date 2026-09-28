<?php

declare(strict_types=1);

namespace PandaPanel\Support\Installer;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * What the published frontend needs from the application around it.
 *
 * Every panel screen is a Vue component that reaches an application by
 * `vendor:publish` and is built by that application's Vite. Three things have
 * to be true for that to work, and none of them is something this package can
 * do on its behalf:
 *
 * 1. **npm dependencies.** The components import sixteen packages. The list
 *    lives in this repository's own `package.json` and is read from there
 *    rather than restated here — a second copy is a copy that goes stale the
 *    first time a component imports something new.
 *
 * 2. **The host seam.** Eighteen modules the components import and do not
 *    ship: `@/routes/*` and `@/actions/*`, which Wayfinder generates from the
 *    application's own routes, and a handful of components a Laravel Vue
 *    starter kit already has. See `frontend/host/README.md` in this
 *    repository for why each one is the application's rather than ours.
 *
 * 3. **Vite.** No entrypoint, no build.
 *
 * Getting any of them wrong fails at `npm run build`, in a message about a
 * module specifier — which is a true error about the wrong thing. Checking
 * here turns each into a sentence naming what to install.
 */
final class FrontendRequirements
{
    /**
     * Where each host-seam module is expected, relative to `resources/js`.
     *
     * Extensionless: a Wayfinder module is `.ts`, a component is `.vue`, and
     * a starter kit may write either as a directory with an `index`. The
     * check tries all of them.
     *
     * @var list<string>
     */
    private const HOST_MODULES = [
        'routes',
        'routes/login',
        'routes/register',
        'routes/password',
        'routes/two-factor',
        'routes/verification',
        'actions/App/Http/Controllers/Settings/ProfileController',
        'actions/App/Http/Controllers/Settings/SecurityController',
        'actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController',
        'components/Heading',
        'components/UserInfo',
        'components/UserMenuContent',
        'components/PasskeyItem',
        'components/PasskeyRegister',
        'components/TwoFactorRecoveryCodes',
        'components/TwoFactorSetupModal',
        'composables/useTwoFactorAuth',
        'types',
        // `FlashToast`, which the panel's broadcasting and flash bridge both
        // import. It was missing from this list, and could not have been
        // caught while a bare directory counted as a module — see below.
        'types/ui',
    ];

    /**
     * How a specifier may be spelled on disk.
     *
     * A bare `''` used to be in this list, which made every directory-shaped
     * entry vacuous: `File::exists()` answers true for a directory, so
     * `@/types` was satisfied by the *folder* this package publishes into and
     * the check could never fail — however empty the folder was of the module
     * actually being imported.
     *
     * `.d.ts` is here because a starter kit writes its shared types as
     * `resources/js/types/index.d.ts`, which is a real answer to `@/types`
     * and was not being looked for.
     *
     * @var list<string>
     */
    private const EXTENSIONS = ['.ts', '.vue', '.d.ts', '/index.ts', '/index.vue', '/index.d.ts'];

    /**
     * This package's own `package.json`, read from wherever it is installed.
     *
     * It has to reach the Composer dist for this to resolve, which is why
     * `.gitattributes` deliberately does not export-ignore it — see
     * `hasNpmManifest()` for what happens when it does not.
     */
    public static function npmManifestPath(): string
    {
        return dirname(__DIR__, 3).'/package.json';
    }

    /**
     * Whether the dependency list can be read at all.
     *
     * Asked separately because "no missing packages" and "I could not look"
     * are the same empty list, and reporting the second as the first is how a
     * `package.json` left out of the Composer archive turned `panel:install`
     * into a command that cheerfully said nothing was missing. The one job it
     * has is to name what is missing.
     */
    public static function hasNpmManifest(): bool
    {
        return File::exists(self::npmManifestPath());
    }

    public static function npmPackages(): array
    {
        $manifest = self::npmManifestPath();

        if (! File::exists($manifest)) {
            return [];
        }

        $decoded = json_decode(File::get($manifest), associative: true);

        if (! is_array($decoded) || ! is_array($decoded['dependencies'] ?? null)) {
            return [];
        }

        $packages = [];

        foreach ($decoded['dependencies'] as $name => $range) {
            if (is_string($name) && is_string($range)) {
                $packages[] = $name.'@'.$range;
            }
        }

        sort($packages);

        return $packages;
    }

    /**
     * What the application's own `package.json` declares, name => range.
     *
     * Read from `package.json` rather than from `node_modules`, because what
     * matters is whether the project has *declared* the dependency — a
     * transitive copy on disk today is one somebody else's upgrade removes
     * tomorrow.
     *
     * Both sections, because which one a project put a build tool in is its
     * business and either satisfies an import.
     *
     * @return array<string, mixed>
     */
    public static function declaredNpmPackages(): array
    {
        $path = base_path('package.json');

        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), associative: true);
        $declared = [];

        if (is_array($decoded)) {
            foreach (['dependencies', 'devDependencies'] as $section) {
                if (is_array($decoded[$section] ?? null)) {
                    $declared += $decoded[$section];
                }
            }
        }

        return $declared;
    }

    /**
     * The npm packages the application does not already have.
     *
     * @return list<string> the same `name@range` pairs, filtered
     */
    public static function missingNpmPackages(): array
    {
        if (! File::exists(base_path('package.json'))) {
            return self::npmPackages();
        }

        $declared = self::declaredNpmPackages();

        return array_values(array_filter(
            self::npmPackages(),
            // `beforeLast` rather than `before`: a scoped package's name
            // starts with the same character the range is joined on.
            static fn (string $package): bool => ! array_key_exists(
                Str::beforeLast($package, '@'),
                $declared,
            ),
        ));
    }

    /**
     * The host-seam modules the application is missing, as `@/…` specifiers.
     *
     * @return list<string>
     */
    public static function missingHostModules(): array
    {
        $missing = [];

        foreach (self::HOST_MODULES as $module) {
            if (! self::exists($module)) {
                $missing[] = '@/'.$module;
            }
        }

        return $missing;
    }

    /**
     * Whether the application has a Vite config at all.
     */
    public static function hasVite(): bool
    {
        return File::exists(base_path('vite.config.ts'))
            || File::exists(base_path('vite.config.js'));
    }

    /**
     * Whether the application is an Inertia application.
     *
     * @return list<string> what is missing, in words, empty when nothing is
     */
    public static function missingInertia(): array
    {
        $missing = [];

        if (! File::exists(resource_path('views/app.blade.php'))) {
            $missing[] = 'an Inertia root view at resources/views/app.blade.php';
        }

        if (! File::exists(app_path('Http/Middleware/HandleInertiaRequests.php'))) {
            $missing[] = 'Inertia\'s middleware (php artisan inertia:middleware)';
        }

        return $missing;
    }

    /**
     * The application entry files, in the order they are worth reporting.
     *
     * @var list<string>
     */
    private const ENTRY_FILES = ['js/app.ts', 'js/app.js', 'js/ssr.ts', 'js/ssr.js'];

    /**
     * Places where the application's Inertia entry overwrites a layout the
     * page declared for itself.
     *
     * Every panel page now names its own layout, so a host that does not
     * mention `panel/` at all is correct and is not reported. What is not
     * correct is the other common shape:
     *
     *     page.default.layout = AppLayout
     *
     * — an unconditional assignment, which replaces the panel shell with the
     * application's own after the page has already asked for it. The panel
     * then renders inside the starter kit's sidebar: no error, HTTP 200, and
     * navigation that silently belongs to somebody else. It is the one thing
     * about this seam that cannot be fixed from inside the package, which is
     * why it is checked rather than documented.
     *
     * `||=`, `??=`, and any assignment whose right-hand side already falls
     * back (`page.default.layout || AppLayout`) are all correct and pass.
     *
     * @return list<array{file: string, line: int, code: string}>
     */
    public static function layoutOverrides(): array
    {
        $found = [];

        foreach (self::ENTRY_FILES as $entry) {
            $path = resource_path($entry);

            if (! File::exists($path)) {
                continue;
            }

            foreach (self::offendingLines(File::get($path)) as $line => $code) {
                $found[] = [
                    'file' => 'resources/'.$entry,
                    'line' => $line,
                    'code' => $code,
                ];
            }
        }

        return $found;
    }

    /**
     * @return array<int, string> one-indexed line number => the line itself
     */
    private static function offendingLines(string $contents): array
    {
        $offending = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            // `||=` and `??=` are already the safe forms, and an assignment
            // that mentions either operator is falling back rather than
            // overwriting.
            if (preg_match('/\.layout\s*(\|\||\?\?)=/', $line) === 1) {
                continue;
            }

            if (preg_match('/\.layout\s*=/', $line) !== 1) {
                continue;
            }

            if (preg_match('/\|\||\?\?/', $line) === 1) {
                continue;
            }

            $offending[$index + 1] = trim($line);
        }

        return $offending;
    }

    private static function exists(string $module): bool
    {
        foreach (self::EXTENSIONS as $extension) {
            if (File::exists(resource_path('js/'.$module.$extension))) {
                return true;
            }
        }

        return false;
    }
}
