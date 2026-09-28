<?php

declare(strict_types=1);

namespace PandaPanel\Support\Installer;

use Illuminate\Support\Facades\File;
use PandaPanel\Support\FrontendPaths;

/**
 * The application around the panel, for an application that does not have one.
 *
 * `FrontendRequirements` answers "what is missing"; this writes it. The split
 * is deliberate — the check has to keep working on an application nobody is
 * installing into, and `panel:assets` and the docs both read it — but the two
 * were only ever half a feature apart. `panel:install` on a Laravel Vue
 * starter kit finished with an empty list of things to do; on a blank
 * `laravel new` it finished with a list of nine, every one of which was a file
 * this package could have written.
 *
 * ## What it will and will not write
 *
 * Only files that are **not there**. Nothing here overwrites, and nothing here
 * merges into a file somebody else authored, with one exception noted on
 * `linkStylesheet()`. An application that already has an entrypoint, a root
 * view, or a `UserMenuContent` has made a decision, and an installer that
 * replaced it would be destroying work to save a step.
 *
 * ## The host seam
 *
 * The panel's published components import nineteen modules they do not ship —
 * `@/routes/*` and `@/actions/*`, which Wayfinder generates, and a handful of
 * components a Laravel Vue starter kit already has. `frontend/host/` in this
 * repository holds a minimal, correctly-typed stand-in for each, used for the
 * package's own type-check.
 *
 * They are copied into an application that has none of them. That is a change
 * of position: they used to be documented as never shipping, on the grounds
 * that a component is the application's design and a vendored one would be
 * overwriting somebody's. Both halves of that are still true, and neither is
 * an argument for a blank application getting a build error instead. So they
 * ship, they are written only where nothing exists, and the installer says out
 * loud that they are stand-ins rather than a design.
 */
final class ApplicationScaffold
{
    /**
     * npm packages the scaffolded `vite.config.ts` needs that
     * `FrontendRequirements::npmPackages()` does not name.
     *
     * That list is the package's runtime `dependencies` — what the components
     * import. This is the build itself: the Vue plugin compiles the `.vue`
     * files, and without it Vite reads a single-file component as JavaScript
     * and fails on the first `<template>`.
     *
     * @var list<string>
     */
    public const BUILD_PACKAGES = ['@vitejs/plugin-vue@^6.0.0'];

    /**
     * The application files a panel needs, keyed by where they land.
     *
     * @return array<string, string> application-relative destination => stub
     */
    public static function files(): array
    {
        return [
            'resources/views/app.blade.php' => 'app.blade.php',
            'resources/js/app.ts' => 'app.ts',
            'resources/css/app.css' => 'app.css',
            'vite.config.ts' => 'vite.config.ts',
        ];
    }

    /**
     * The ones this application does not have.
     *
     * `vite.config.ts` is missing only when there is no Vite config *at all*:
     * Vite prefers `vite.config.js` over `.ts` when both exist, so writing one
     * beside a `laravel new`'s own would produce a file that is never read and
     * a build that fails for a reason nothing on disk explains. That case is
     * `staleViteConfig()`, and it is a replacement rather than a write.
     *
     * @return list<string> application-relative destinations
     */
    public static function missing(): array
    {
        $missing = [];

        foreach (array_keys(self::files()) as $relative) {
            if ($relative === 'vite.config.ts' && FrontendRequirements::hasVite()) {
                continue;
            }

            if (! File::exists(base_path($relative))) {
                $missing[] = $relative;
            }
        }

        return $missing;
    }

    /**
     * Writes one of `files()`, and reports whether it did.
     */
    public static function write(string $relative): bool
    {
        $files = self::files();

        if (! isset($files[$relative]) || File::exists(base_path($relative))) {
            return false;
        }

        File::ensureDirectoryExists(dirname(base_path($relative)));
        File::put(base_path($relative), File::get(self::stub($files[$relative])));

        return true;
    }

    /**
     * A `vite.config.js` that cannot build the panel, or null.
     *
     * `laravel new` ships one, and it has neither the Vue plugin nor the `@`
     * alias every published component imports through. It is also the file
     * Vite reads *first*, so it cannot be left in place beside a working
     * `vite.config.ts`.
     *
     * Reported rather than rewritten. Editing somebody's build config by
     * regular expression is how an installer breaks a project it was asked to
     * help; moving it aside is reversible in one command, and the caller asks
     * before doing even that.
     */
    public static function staleViteConfig(): ?string
    {
        foreach (['vite.config.js', 'vite.config.mjs'] as $candidate) {
            $path = base_path($candidate);

            if (! File::exists($path)) {
                continue;
            }

            $contents = File::get($path);

            // Both, because either one alone is a build that fails: no Vue
            // plugin and every `.vue` file is a syntax error, no alias and
            // every `@/…` import is unresolved.
            if (str_contains($contents, 'plugin-vue') && str_contains($contents, "'@'")) {
                return null;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Moves a Vite config aside so the scaffolded one is the one Vite reads.
     *
     * `.bak` beside the original rather than a delete: it is the application's
     * file, it may well have plugins worth copying across, and a person who
     * disagrees with this undoes it with `mv`.
     *
     * @return string|null the path the old config was kept at
     */
    public static function replaceViteConfig(string $relative): ?string
    {
        $path = base_path($relative);

        if (! File::exists($path)) {
            return null;
        }

        $backup = $path.'.bak';

        File::move($path, $backup);

        if (! self::write('vite.config.ts')) {
            // Nothing was written, so nothing should have been moved.
            File::move($backup, $path);

            return null;
        }

        return $relative.'.bak';
    }

    /**
     * Points an existing `resources/css/app.css` at the panel's stylesheet.
     *
     * The one place anything here edits a file it did not write, and it earns
     * the exception: without this line the panel renders unstyled, and there
     * is no other file for it to go in. One `@import`, inserted after the
     * imports already there — CSS requires them first — and never twice.
     *
     * The panel's own stylesheet imports Tailwind, so an `app.css` that also
     * imports it now does so twice. That is harmless and it is noise, which is
     * why the installer says so rather than silently deleting a line out of
     * the application's stylesheet.
     *
     * @return bool whether a line was added
     */
    public static function linkStylesheet(): bool
    {
        $path = resource_path('css/app.css');

        if (! File::exists($path) || ! File::exists(resource_path('css/panda-panel.css'))) {
            return false;
        }

        $contents = File::get($path);

        if (str_contains($contents, 'panda-panel.css')) {
            return false;
        }

        $import = "@import './panda-panel.css';";
        $lines = preg_split('/\R/', $contents) ?: [];
        $after = -1;

        foreach ($lines as $index => $line) {
            if (str_starts_with(ltrim($line), '@import')) {
                $after = $index;
            }
        }

        array_splice($lines, $after + 1, 0, $after === -1 ? [$import, ''] : [$import]);

        File::put($path, implode("\n", $lines));

        return true;
    }

    /**
     * The host-seam stand-ins, keyed by where they land in the application.
     *
     * @return array<string, string> application-relative destination => absolute source
     */
    public static function hostModules(): array
    {
        $root = self::packagePath('frontend/host');

        if (! File::isDirectory($root)) {
            return [];
        }

        $modules = [];

        foreach (File::allFiles($root) as $file) {
            // The seam's own README and the `routes/shape.ts` helper the
            // stand-ins share. `shape.ts` goes with them — it is what they
            // import — and the README does not.
            if ($file->getExtension() === 'md') {
                continue;
            }

            $modules['resources/js/'.$file->getRelativePathname()] = $file->getPathname();
        }

        ksort($modules);

        return $modules;
    }

    /**
     * The stand-ins this application has no module of its own for.
     *
     * Existence is checked the way a bundler resolves — `@/components/Heading`
     * is satisfied by `Heading.vue`, `Heading.ts`, or `Heading/index.*` — so a
     * starter kit that writes a component as a directory keeps it.
     *
     * @return array<string, string> application-relative destination => absolute source
     */
    public static function missingHostModules(): array
    {
        return array_filter(
            self::hostModules(),
            static fn (string $source, string $relative): bool => ! self::resolves($relative),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Copies the stand-ins this application is missing.
     *
     * @return list<string> what was written, application-relative
     */
    public static function writeHostModules(): array
    {
        $written = [];

        foreach (self::missingHostModules() as $relative => $source) {
            File::ensureDirectoryExists(dirname(base_path($relative)));
            File::copy($source, base_path($relative));

            $written[] = $relative;
        }

        return $written;
    }

    /**
     * Whether an application module already answers this specifier.
     *
     * The extension is dropped before the check, so a stand-in written as
     * `components/Heading.vue` is satisfied by the application's
     * `components/Heading.ts` or `components/Heading/index.vue` just as a
     * bundler would have it.
     */
    private static function resolves(string $relative): bool
    {
        $base = preg_replace('/\.(vue|ts|js|d\.ts)$/', '', $relative) ?? $relative;

        foreach (['.ts', '.vue', '.js', '.d.ts', '/index.ts', '/index.vue', '/index.d.ts'] as $extension) {
            if (File::exists(base_path($base.$extension))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the panel's own components have been published yet.
     *
     * Asked before the host seam is written, because a stand-in is only ever
     * needed by a component that imports it.
     */
    public static function frontendIsPublished(): bool
    {
        return File::isDirectory(FrontendPaths::panel());
    }

    private static function stub(string $name): string
    {
        return self::packagePath('stubs/install/'.$name.'.stub');
    }

    private static function packagePath(string $path): string
    {
        return dirname(__DIR__, 3).'/'.$path;
    }
}
