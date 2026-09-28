<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PandaPanel\Support\FrontendPaths;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Rewrites the frontend icon registry from the names the PHP actually asks
 * for.
 *
 * The registry stays a build-time allowlist — an unknown name still resolves
 * to nothing — but it is no longer maintained by hand. Lucide ships 1768
 * icons; a panel uses a couple of dozen, and only those should reach the
 * bundle.
 *
 * Names are read from source rather than from booted panels on purpose. An
 * icon can be declared in a place no runtime walk reaches: a wizard step, a
 * filter tab, a header action built inside a method. The literal in the file
 * is the one thing every declaration has in common.
 */
final class SyncPanelIconsCommand extends Command
{
    protected $signature = 'panel:icons {--check : Fail instead of writing, for CI}';

    protected $description = 'Rewrite the panel icon registry from the icons the panels declare';

    /**
     * Every shape an icon name is declared in: the fluent setter, the static
     * property, the named argument, the array key a serialized header action
     * or sub-navigation entry uses, and the prime component whose whole
     * argument is an icon.
     */
    private const PATTERNS = [
        "/->icon\(\s*'([a-z0-9-]+)'/",
        "/->icon\(\s*'[^']+'\s*,\s*'([a-z0-9-]+)'/",
        "/->darkIcon\(\s*'([a-z0-9-]+)'/",
        "/\\\$navigationIcon\s*=\s*'([a-z0-9-]+)'/",
        "/icon:\s*'([a-z0-9-]+)'/",
        "/'icon'\s*=>\s*'([a-z0-9-]+)'/",
        "/\\bIcon::make\(\s*'([a-z0-9-]+)'/",
    ];

    /**
     * The body of a method literally named `icon()`.
     *
     * An enum that answers "which icon does this case wear" holds its names in
     * match arms, which none of the patterns above can see — and an icon that
     * is never registered renders as nothing at all, silently. Scoped to the
     * method name rather than matched loosely, so a `match` returning ordinary
     * strings elsewhere is not mistaken for a list of icons.
     */
    private const ICON_METHOD = "/function icon\(\)\s*:\s*string\s*\{(.*?)\n    \}/s";

    public function handle(): int
    {
        $available = $this->availableIcons();
        $requested = $this->requestedIcons();

        // With no Lucide on disk there is nothing to check names against, and
        // treating every name as unknown would empty the registry — every icon
        // in the panel would silently disappear because somebody ran this
        // before `npm install`. The names are taken as given instead.
        $checkable = $available !== [];

        $unknown = $checkable ? array_values(array_diff($requested, $available)) : [];
        $resolved = $checkable ? array_values(array_intersect($requested, $available)) : $requested;

        sort($resolved);

        if ($unknown !== []) {
            // A name Lucide does not have is a typo, and a typo here shows up
            // as a button with no icon and no error.
            $this->components->error('Not a Lucide icon: '.implode(', ', $unknown));
        }

        $contents = $this->render($resolved);
        $path = FrontendPaths::panel('icons/registry.ts');

        if ($this->option('check')) {
            $current = File::exists($path) ? File::get($path) : '';

            if ($current !== $contents) {
                $this->components->error('The icon registry is out of date. Run php artisan panel:icons.');

                return self::FAILURE;
            }

            $this->components->info('The icon registry is up to date.');

            return $unknown === [] ? self::SUCCESS : self::FAILURE;
        }

        File::put($path, $contents);

        $this->components->info(sprintf('Registered %d icons.', count($resolved)));

        return $unknown === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every icon Lucide actually ships, taken from the package on disk.
     *
     * @return array<int, string>
     */
    private function availableIcons(): array
    {
        $directory = base_path('node_modules/@lucide/vue/dist/esm/icons');

        if (! File::isDirectory($directory)) {
            $this->components->warn('@lucide/vue is not installed; nothing to check names against.');

            return [];
        }

        return collect(File::files($directory))
            ->filter(static fn (SplFileInfo $file): bool => $file->getExtension() === 'mjs')
            ->map(static fn (SplFileInfo $file): string => $file->getFilenameWithoutExtension())
            ->values()
            ->all();
    }

    /**
     * Every place an icon name can be declared: the application's own panels,
     * and the framework's own source.
     *
     * The second is not optional. Half the icons a panel renders belong to
     * actions the framework ships — delete, edit, export — and once the
     * framework is a package in `vendor`, a scan of `app/` alone would rewrite
     * the registry without them. Every built-in action would then render with
     * no icon and no error.
     *
     * @return list<string>
     */
    private function scanPaths(): array
    {
        $paths = [app_path(), dirname(__DIR__, 2)];

        return array_values(array_filter($paths, File::isDirectory(...)));
    }

    /**
     * @return array<int, string>
     */
    private function requestedIcons(): array
    {
        $names = [];

        $files = array_merge(...array_map(
            static fn (string $path): array => File::allFiles($path),
            $this->scanPaths(),
        ));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = $file->getContents();

            foreach (self::PATTERNS as $pattern) {
                preg_match_all($pattern, $contents, $matches);

                $names = [...$names, ...$matches[1]];
            }

            preg_match_all(self::ICON_METHOD, $contents, $bodies);

            foreach ($bodies[1] as $body) {
                preg_match_all("/'([a-z0-9-]+)'/", $body, $matches);

                $names = [...$names, ...$matches[1]];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<int, string>  $icons
     */
    private function render(array $icons): string
    {
        $imports = implode("\n", array_map(
            static fn (string $icon): string => '    '.Str::studly($icon).',',
            $icons,
        ));

        $entries = implode("\n", array_map(
            static function (string $icon): string {
                $key = str_contains($icon, '-') ? "'{$icon}'" : $icon;

                return sprintf('    %s: %s,', $key, Str::studly($icon));
            },
            $icons,
        ));

        return <<<TS
        import {
        {$imports}
        } from '@lucide/vue';
        import type { Component } from 'vue';

        /**
         * Generated by `php artisan panel:icons`. Do not edit by hand.
         *
         * Icon names arrive from the server as plain strings. They resolve
         * through this map and nothing else: a name that is not a key here
         * renders no icon.
         *
         * This is deliberate. Resolving an arbitrary server-supplied name to a
         * component would let panel metadata reach into the bundle, and dynamic
         * imports keyed on a runtime string are not statically analysable
         * either. Lucide ships 1768 icons; only the ones a panel declares
         * belong in the bundle.
         */
        const ICONS = {
        {$entries}
        } satisfies Record<string, Component>;

        export type PanelIconName = keyof typeof ICONS;

        export function isPanelIconName(name: string): name is PanelIconName {
            return name in ICONS;
        }

        /** Names already complained about, so one typo is one warning. */
        const missing = new Set<string>();

        /**
         * Returns null for an unknown or absent name. Callers render no icon
         * rather than a broken one, and production stays console-clean.
         *
         * An unknown name used to be indistinguishable from no name at all:
         * both drew nothing, so a mistyped icon — or, far more often, one
         * declared in PHP after this file was last generated — was a missing
         * icon with no way to find out why. This registry is written by
         * `php artisan panel:icons`, and forgetting to re-run it is the whole
         * failure mode.
         *
         * Development only. In production the icon is still simply absent,
         * because a console message on a live panel helps nobody and this is
         * a build problem rather than a runtime one.
         */
        export function resolveIcon(name: string | null | undefined): Component | null {
            if (typeof name !== 'string') {
                return null;
            }

            if (!isPanelIconName(name)) {
                if (import.meta.env.DEV && !missing.has(name)) {
                    missing.add(name);

                    console.warn(
                        '[panel] The icon [' +
                            name +
                            '] is not in the icon registry, so nothing is drawn for it. ' +
                            'Run `php artisan panel:icons` to rebuild the registry from ' +
                            'the icons your panels declare.',
                    );
                }

                return null;
            }

            return ICONS[name];
        }

        TS;
    }
}
