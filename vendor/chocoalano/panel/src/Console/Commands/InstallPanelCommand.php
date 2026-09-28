<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;

use PandaPanel\Support\Installer\ApplicationScaffold;
use PandaPanel\Support\Installer\AssetManifest;
use PandaPanel\Support\Installer\FrontendRequirements;
use PandaPanel\Support\Installer\InertiaMiddlewareRegistrar;
use PandaPanel\Support\Installer\PackageManager;
use PandaPanel\Support\Installer\PanelRegistrar;

/**
 * The one command a fresh install runs.
 *
 * Everything it does is available separately — `vendor:publish` by tag,
 * `make:panel`, `panel:user` — so nothing here is a step that cannot be taken
 * by hand or repeated. It exists because the order matters and because an
 * install that stops one step short of working is the same as an install that
 * failed.
 *
 * ## What changed, and why
 *
 * This used to publish, scaffold, register, and then *describe* everything
 * else. On a Laravel Vue starter kit that was almost the whole job: the root
 * view, the entrypoint, the Vite config, the npm dependencies and eighteen of
 * the nineteen host modules were already there, and the closing list was
 * usually empty.
 *
 * On a blank `laravel new` it was a list of nine, and every single one of them
 * was something this package could have done. "Install the panel" answered
 * with nine pieces of homework is not an installer, it is a checklist with a
 * progress bar. So the steps that were descriptions are now offers:
 *
 * 1. Publish the config, the frontend, and — if asked — the migrations.
 * 2. Give the application what an Inertia + Vue build needs, where it has
 *    none: the root view, `app.ts`, `app.css`, a Vite config with the `@`
 *    alias every published component imports through, and Inertia's middleware
 *    registered in `bootstrap/app.php`.
 * 3. Fill the host seam — Wayfinder where it is installed, the stand-ins
 *    this package ships for whatever is still missing.
 * 4. Scaffold the first panel, and register it in `config/panda-panel.php`,
 *    which is what makes its URL answer.
 * 5. Install the npm dependencies the components import, and offer the build.
 * 6. Offer an account that can sign in.
 * 7. Report anything still outstanding, in words, once.
 *
 * ## What it will not do
 *
 * Nothing here overwrites a file the application already has. Every write is
 * to a path where there was nothing, with one exception — an `app.css` gains
 * one `@import` line — and one near-exception: a `vite.config.js` that cannot
 * build a Vue application is *moved aside*, never edited, and only after
 * saying so and asking.
 *
 * Every step that touches the filesystem or the network asks first. With
 * `--no-interaction` the safe answer is assumed: files that do not exist are
 * written, and nothing that moves, replaces, or reaches the network runs at
 * all. `--npm` and `--no-npm` settle the dependency install without a prompt.
 *
 * The guest redirect used to be a step and a manual one. It is now registered
 * by the service provider — see
 * `PandaPanelServiceProvider::registerGuestRedirect()`, and the
 * `register_guest_redirect` config key for the case where an application
 * would rather own it. So is the redirect *after* signing in — see
 * `PandaPanel\Support\PanelPostLogin` and `login_redirect`.
 */
final class InstallPanelCommand extends Command
{
    protected $signature = 'panel:install
        {--panel=Admin : The name of the first panel to scaffold}
        {--no-panel : Publish and configure without scaffolding a panel}
        {--no-user : Skip the offer to create a signing-in account}
        {--no-scaffold : Do not write any of the application files a panel needs}
        {--npm : Install the npm dependencies without asking}
        {--no-npm : Never run npm}
        {--force : Overwrite files that already exist}';

    protected $description = 'Publish the panel, give the application what it needs to render one, scaffold a first panel, and register it';

    /**
     * What could not be done for the application, collected as it goes and
     * printed once at the end.
     *
     * Reported together rather than as they happen: an install that
     * interleaves five successes with three warnings is an install whose
     * warnings are read as noise.
     *
     * @var list<string>
     */
    private array $outstanding = [];

    public function handle(): int
    {
        $this->components->info('Installing Panda Panel.');

        $this->publish('panda-panel-config');
        $this->publish('panda-panel-assets');

        // Records what was just published, which is what lets a later
        // `panel:assets` tell a file this application edited from one that
        // has simply fallen behind. Without it, every future upgrade is a
        // choice between overwriting your work and updating nothing.
        AssetManifest::write(AssetManifest::read());

        if ($this->shouldPublishMigrations()) {
            $this->publish('panda-panel-migrations');
        }

        $this->scaffoldApplication();

        $panel = $this->scaffoldPanel();

        if ($panel !== null) {
            $this->registerPanel($panel);
        }

        $this->reportHomeRedirect();
        $this->installDependencies();
        $this->checkFrontend();
        $this->offerUser($panel);

        $this->reportOutstanding();

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | The application around the panel
    |--------------------------------------------------------------------------
    */

    /**
     * Everything a panel needs from the application, where it is not there.
     *
     * In this order for one reason: the host seam is resolved last, because
     * Wayfinder generates half of it and the stand-ins are only for what
     * Wayfinder did not write.
     */
    private function scaffoldApplication(): void
    {
        if ($this->option('no-scaffold')) {
            return;
        }

        $this->ensureInertiaMiddleware();
        $this->ensureApplicationFiles();
        $this->ensureViteConfig();
        $this->ensureStylesheetLink();
        $this->ensureHostModules();
    }

    /**
     * Inertia's middleware: the class, and the line that puts it in the group.
     *
     * Two steps because `inertia:middleware` is only the first of them, and
     * the second is the one that makes it run.
     */
    private function ensureInertiaMiddleware(): void
    {
        if (! InertiaMiddlewareRegistrar::isPublished()) {
            if (! $this->agree('Publish Inertia\'s middleware? (php artisan inertia:middleware)')) {
                return;
            }

            $this->call('inertia:middleware');
        }

        $result = InertiaMiddlewareRegistrar::register();

        match ($result) {
            InertiaMiddlewareRegistrar::REGISTERED => $this->components->info(
                'Added Inertia\'s middleware to the web group in bootstrap/app.php.',
            ),
            InertiaMiddlewareRegistrar::ALREADY_PRESENT,
            InertiaMiddlewareRegistrar::NO_MIDDLEWARE => null,
            default => $this->outstanding[] = sprintf(
                "Add Inertia's middleware to the web group in bootstrap/app.php:\n\n"
                    ."       ->withMiddleware(function (Middleware \$middleware): void {\n"
                    ."           \$middleware->web(append: [\n"
                    ."               \\%s::class,\n"
                    ."           ]);\n"
                    ."       })\n\n"
                    .'     (%s)',
                InertiaMiddlewareRegistrar::middleware(),
                $result === InertiaMiddlewareRegistrar::NO_BOOTSTRAP
                    ? 'There is no bootstrap/app.php'
                    : 'The file has been reshaped, so it was left alone',
            ),
        };
    }

    /**
     * The root view, the entrypoint and the stylesheet.
     *
     * Asked as one question rather than four: they are one decision — "does
     * this application have a frontend yet" — and an installer that asks four
     * times is an installer people stop reading.
     */
    private function ensureApplicationFiles(): void
    {
        $missing = array_values(array_filter(
            ApplicationScaffold::missing(),
            static fn (string $relative): bool => $relative !== 'vite.config.ts',
        ));

        if ($missing === []) {
            return;
        }

        $agreed = $this->agree(sprintf(
            'Write the %d file(s) a panel needs and this application does not have?',
            count($missing),
        ), hint: implode(', ', $missing));

        if (! $agreed) {
            $this->outstanding[] = 'These are still missing, and a panel screen cannot render '
                ."without them:\n\n       ".implode("\n       ", $missing);

            return;
        }

        foreach ($missing as $relative) {
            if (ApplicationScaffold::write($relative)) {
                $this->components->twoColumnDetail($relative, '<fg=green>written</>');
            }
        }
    }

    /**
     * A Vite config that can build a Vue application.
     *
     * The awkward one. `laravel new` ships a `vite.config.js` with neither the
     * Vue plugin nor the `@` alias, and Vite reads `.js` before `.ts` — so a
     * config written beside it is a config that is never read, and a build
     * that fails for a reason nothing on disk explains.
     *
     * Moved aside rather than edited, and never without being asked, including
     * under `--no-interaction`: rewriting somebody's build config is not a
     * thing to do quietly.
     */
    private function ensureViteConfig(): void
    {
        $stale = ApplicationScaffold::staleViteConfig();

        if ($stale === null) {
            if (! FrontendRequirements::hasVite() && ApplicationScaffold::write('vite.config.ts')) {
                $this->components->twoColumnDetail('vite.config.ts', '<fg=green>written</>');
            }

            return;
        }

        $agreed = $this->agree(
            "Replace {$stale} with a vite.config.ts that can build the panel?",
            nonInteractive: false,
            hint: "Yours has no Vue plugin and no '@' alias, and Vite reads it first. "
                ."It is kept as {$stale}.bak.",
        );

        if (! $agreed) {
            $this->outstanding[] = sprintf(
                "%s cannot build the panel. It needs the Vue plugin and the '@' alias every "
                    ."published component imports through:\n\n"
                    ."       import vue from '@vitejs/plugin-vue';\n\n"
                    ."       plugins: [laravel({ input: ['resources/css/app.css', 'resources/js/app.ts'] }), vue(), tailwindcss()],\n"
                    ."       resolve: { alias: { '@': resolve(__dirname, 'resources/js') } }",
                $stale,
            );

            return;
        }

        $backup = ApplicationScaffold::replaceViteConfig($stale);

        if ($backup === null) {
            $this->outstanding[] = "Could not replace {$stale}. Write a vite.config.ts by hand.";

            return;
        }

        $this->components->twoColumnDetail('vite.config.ts', '<fg=green>written</>');
        $this->components->twoColumnDetail($backup, '<fg=yellow>kept</>');
    }

    /**
     * Points the application's stylesheet at the panel's.
     *
     * Only where `app.css` was already there — the scaffolded one imports it
     * to begin with — and only once.
     */
    private function ensureStylesheetLink(): void
    {
        if (ApplicationScaffold::linkStylesheet()) {
            $this->components->twoColumnDetail(
                'resources/css/app.css',
                '<fg=green>imports panda-panel.css</>',
            );

            $this->outstanding[] = 'resources/css/app.css now imports panda-panel.css, which imports '
                ."Tailwind itself.\n\n     If your app.css has its own `@import 'tailwindcss';` you "
                .'can delete that line — it is imported twice otherwise, which builds correctly and '
                .'is simply larger than it needs to be.';
        }
    }

    /**
     * The nineteen modules the published components import and do not ship.
     *
     * Wayfinder first, because `@/routes/*` and `@/actions/*` are *generated*
     * from this application's own route table and a real one beats a stand-in
     * every time. Whatever is still missing afterwards gets the stand-in this
     * package ships — which is a working module and an honest placeholder,
     * not a design.
     */
    private function ensureHostModules(): void
    {
        if (! ApplicationScaffold::frontendIsPublished()) {
            return;
        }

        $this->generateWayfinder();

        $missing = ApplicationScaffold::missingHostModules();

        if ($missing === []) {
            return;
        }

        $agreed = $this->agree(sprintf(
            'Write stand-ins for the %d module(s) the panel components import and this application does not have?',
            count($missing),
        ), hint: 'Minimal but correctly typed, and yours to replace. A Laravel Vue starter kit has richer versions of most of them.');

        if (! $agreed) {
            return;
        }

        $written = ApplicationScaffold::writeHostModules();

        $this->components->twoColumnDetail(
            'resources/js (host modules)',
            '<fg=green>'.count($written).' written</>',
        );

        $this->outstanding[] = 'The host modules just written are stand-ins — they resolve and they '
            ."type-check, they are not a design.\n\n     "
            .'`components/*` are yours to style; `routes/*` and `actions/*` are Wayfinder\'s to '
            .'generate, and will be overwritten the moment you run `php artisan wayfinder:generate`.';
    }

    /**
     * Generates the Wayfinder modules, when Wayfinder is installed.
     *
     * Not installed for the application: `composer require` inside a running
     * artisan command changes the autoloader underneath the process that is
     * using it, and an installer is the wrong place to find out what that does.
     */
    private function generateWayfinder(): void
    {
        $console = $this->getApplication();

        if ($console === null || ! $console->has('wayfinder:generate')) {
            $this->outstanding[] = 'Wayfinder is not installed, so `@/routes/*` and `@/actions/*` '
                ."are stand-ins rather than generated from your own routes:\n\n"
                ."       composer require laravel/wayfinder --dev\n"
                ."       php artisan wayfinder:generate\n\n"
                .'     Run it again after every route change — the panel\'s links come from it.';

            return;
        }

        $this->call('wayfinder:generate');
    }

    /*
    |--------------------------------------------------------------------------
    | Dependencies
    |--------------------------------------------------------------------------
    */

    /**
     * The npm packages the published components import.
     *
     * Offered rather than assumed, and never under `--no-interaction` unless
     * `--npm` says so: this writes to `node_modules`, touches the lockfile,
     * and reaches the network — three things a package should not do to a
     * project without being told to.
     */
    private function installDependencies(): void
    {
        $packages = [...FrontendRequirements::missingNpmPackages(), ...$this->missingBuildPackages()];

        if ($packages === []) {
            return;
        }

        $command = 'npm install '.implode(" \\\n       ", $packages);

        if ($this->option('no-npm') || ! PackageManager::hasNpm()) {
            $this->outstanding[] = 'Install the npm dependencies the components import, then rebuild:'
                ."\n\n     ".$command."\n     npm run build";

            return;
        }

        $agreed = $this->option('npm') || $this->agree(
            sprintf('Install the %d npm package(s) the panel components import?', count($packages)),
            nonInteractive: false,
            hint: $command,
        );

        if (! $agreed) {
            $this->outstanding[] = 'Install the npm dependencies the components import, then rebuild:'
                ."\n\n     ".$command."\n     npm run build";

            return;
        }

        $this->newLine();

        $installed = PackageManager::install($packages, fn (string $chunk) => $this->output->write($chunk));

        if (! $installed) {
            $this->outstanding[] = "`npm install` did not finish. Run it by hand:\n\n     "
                .$command."\n     npm run build";

            return;
        }

        $this->components->info('Installed the npm dependencies.');
        $this->offerBuild();
    }

    /**
     * The build, which is the step between a published component and a screen.
     */
    private function offerBuild(): void
    {
        $agreed = $this->option('npm') || $this->agree(
            'Build the frontend now? (npm run build)',
            nonInteractive: false,
        );

        if (! $agreed) {
            $this->outstanding[] = "Build the frontend before opening a panel:\n\n     npm run build";

            return;
        }

        $this->newLine();

        if (! PackageManager::build(fn (string $chunk) => $this->output->write($chunk))) {
            $this->outstanding[] = '`npm run build` did not finish. Run it by hand and read the error:'
                ."\n\n     npm run build";
        }
    }

    /**
     * The build-time packages the scaffolded Vite config needs.
     *
     * Separate from `FrontendRequirements::missingNpmPackages()`, which is
     * about what the *components* import. Nothing imports `@vitejs/plugin-vue`;
     * without it, Vite reads a single-file component as JavaScript.
     *
     * @return list<string>
     */
    private function missingBuildPackages(): array
    {
        $declared = FrontendRequirements::declaredNpmPackages();

        return array_values(array_filter(
            ApplicationScaffold::BUILD_PACKAGES,
            static fn (string $package): bool => ! array_key_exists(
                Str::beforeLast($package, '@'),
                $declared,
            ),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | The panel itself
    |--------------------------------------------------------------------------
    */

    /**
     * @return string|null the panel name, or null when none was scaffolded
     */
    private function scaffoldPanel(): ?string
    {
        if ($this->option('no-panel')) {
            return null;
        }

        $panel = (string) $this->option('panel');

        $this->call('make:panel', [
            'name' => $panel,
            '--force' => (bool) $this->option('force'),
        ]);

        return $panel;
    }

    /**
     * Adds the new panel to the `panels` list.
     *
     * Without this the install finishes, the provider exists, and the panel's
     * URL 404s — the single most common "it did not work" after an install,
     * and the one thing standing between a scaffold and a working screen.
     */
    private function registerPanel(string $panel): void
    {
        $provider = "App\\Panels\\{$panel}\\{$panel}PanelProvider";

        $result = PanelRegistrar::register($provider);

        match ($result) {
            PanelRegistrar::REGISTERED => $this->components->info(
                "Registered {$provider} in config/panda-panel.php.",
            ),
            PanelRegistrar::ALREADY_PRESENT => $this->components->info(
                'That panel is already registered in config/panda-panel.php.',
            ),
            default => $this->outstanding[] = sprintf(
                "Add %s::class to 'panels' in config/panda-panel.php. (%s)",
                $provider,
                $result === PanelRegistrar::NO_CONFIG
                    ? 'The config has not been published'
                    : 'The config has been reshaped, so it was left alone',
            ),
        };
    }

    /**
     * Says out loud where signing in now lands.
     *
     * Two redirects the package registers, and both change a screen the
     * application already had: `/dashboard` leads into the panel, and so does
     * finishing a sign-in. A redirect nobody was told about is a bug report.
     * Printed rather than asked, because each answer lives in config and is
     * one line to reverse.
     */
    private function reportHomeRedirect(): void
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('panda-panel.home_redirect', []);

        if (config('panda-panel.login_redirect', true) === true) {
            $this->components->info(
                'Signing in at a panel now lands in that panel rather than on fortify.home. '
                    .'Set login_redirect to false in config/panda-panel.php to keep your own.',
            );
        }

        if (($config['enabled'] ?? false) !== true) {
            return;
        }

        /** @var list<string> $paths */
        $paths = array_values(array_filter(
            (array) ($config['paths'] ?? []),
            static fn (mixed $path): bool => is_string($path) && $path !== '',
        ));

        if ($paths === []) {
            return;
        }

        $this->components->info(sprintf(
            'Signed-in visitors to /%s now land in the panel. '
                .'Set home_redirect.enabled to false in config/panda-panel.php to keep your own.',
            implode(', /', $paths),
        ));
    }

    /**
     * Whatever is still missing after everything above.
     *
     * Every one of these used to be reported on a starter kit too. Most are
     * now written rather than named, so what is left here is the genuinely
     * outstanding: a step somebody declined, a package manager that is not
     * installed, and the one thing that cannot be fixed from inside this
     * package at all — an entrypoint that overwrites the layout a panel page
     * asked for.
     */
    private function checkFrontend(): void
    {
        foreach (FrontendRequirements::missingInertia() as $missing) {
            $this->outstanding[] = "This application is missing {$missing}. "
                .'Every panel screen is an Inertia response and will 500 without it.';
        }

        if (! FrontendRequirements::hasVite()) {
            $this->outstanding[] = 'This application has no vite.config — the published '
                .'components are Vue and have to be built by something.';
        }

        // Before the list itself, because an empty list means two different
        // things and only one of them is good news.
        if (! FrontendRequirements::hasNpmManifest()) {
            $this->outstanding[] = sprintf(
                "This package's own package.json is not installed at %s, so the npm dependencies "
                    ."the published components import could not be checked at all.\n\n"
                    .'     This is a packaging fault rather than something wrong with your application: '
                    .'the file is export-ignored out of the Composer archive. Report it, and in the '
                    .'meantime install the frontend dependencies listed in the package repository by hand.',
                FrontendRequirements::npmManifestPath(),
            );
        }

        foreach (FrontendRequirements::layoutOverrides() as $override) {
            $this->outstanding[] = sprintf(
                "%s line %d overwrites the layout every panel page declares:\n\n       %s\n\n"
                    ."     Make it fall back instead, so a page that names its own layout keeps it:\n\n"
                    ."       page.default.layout ??= AppLayout\n\n"
                    .'     Left as it is, every panel screen renders inside your application shell — '
                    .'with your sidebar, not the panel navigation, and no error to say so.',
                $override['file'],
                $override['line'],
                $override['code'],
            );
        }

        $modules = FrontendRequirements::missingHostModules();

        if ($modules === []) {
            return;
        }

        // Deliberately a list rather than a count: which ones are missing says
        // what to do. All of `@/routes/*` and `@/actions/*` means Wayfinder
        // has not run; a handful of components means this is not a starter
        // kit application.
        $this->outstanding[] = 'The published components import these modules, which belong to '
            ."your application and are not there yet:\n\n       "
            .implode("\n       ", $modules)
            ."\n\n     `@/routes/*` and `@/actions/*` are generated — run "
            .'`php artisan wayfinder:generate`. The rest come with a Laravel Vue starter kit.';
    }

    /**
     * Offers the account that makes the login page usable.
     *
     * Offered rather than assumed: an install into an application that
     * already has users does not need one, and creating a row in somebody's
     * user table uninvited is not a thing an installer should do.
     */
    private function offerUser(?string $panel): void
    {
        if ($this->option('no-user') || ! $this->input->isInteractive()) {
            return;
        }

        if (! confirm(label: 'Create a user who can sign in?', default: false)) {
            return;
        }

        $this->call('panel:user', $panel === null ? [] : ['--panel' => mb_strtolower($panel)]);
    }

    private function reportOutstanding(): void
    {
        $this->newLine();

        if ($this->outstanding === []) {
            $this->components->info('Done. Nothing is left to do by hand.');

            return;
        }

        $this->components->warn(sprintf(
            '%d thing(s) this package cannot do for your application:',
            count($this->outstanding),
        ));

        foreach ($this->outstanding as $index => $step) {
            $this->newLine();
            $this->line('  '.($index + 1).'. '.$step);
        }

        $this->newLine();
    }

    /**
     * A yes/no step.
     *
     * `$nonInteractive` is what a scripted run gets without being asked, and
     * it is the whole of this method's reason to exist: writing a file that is
     * not there is safe enough to assume, and moving somebody's build config
     * or reaching the network is not.
     */
    private function agree(string $label, bool $nonInteractive = true, ?string $hint = null): bool
    {
        if (! $this->input->isInteractive()) {
            return $nonInteractive;
        }

        return confirm(label: $label, default: true, hint: $hint ?? '');
    }

    private function publish(string $tag): void
    {
        $this->call('vendor:publish', [
            '--tag' => $tag,
            '--force' => (bool) $this->option('force'),
        ]);
    }

    /**
     * Whether to hand the migrations over to the application.
     *
     * They already run from the package, so this is about ownership rather
     * than about the tables existing: a project that wants them in its own
     * `database/migrations` — to edit, or to keep one directory as the record
     * of its schema — says so here. Offered rather than assumed, because a
     * published copy and a package copy of the same migration is a schema
     * applied twice.
     */
    private function shouldPublishMigrations(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        return confirm(
            label: 'Publish the migrations into database/migrations?',
            default: false,
            hint: 'They already run from the package. Publish only to own them, '
                .'and set load_migrations to false in config/panda-panel.php if you do.',
        );
    }
}
