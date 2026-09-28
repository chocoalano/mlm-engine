<?php

declare(strict_types=1);

namespace PandaPanel\Cache;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelRegistry;
use PandaPanel\Discovery\PanelDiscoverer;

/**
 * The cached list of classes each panel owns.
 *
 * Only class names are stored, never resolved metadata. Authorization,
 * navigation active state, badge values, record data, and widget data all
 * depend on the current user or URL, so caching any of them would serve one
 * user's answers to another.
 *
 * When the manifest exists, discovery does not run at all: no filesystem
 * scan, no reflection, nothing per request. When it does not, discovery runs
 * once per panel during boot.
 */
final class PanelManifest
{
    /** @var array<string, array{resources: list<string>, pages: list<string>, widgets: list<string>}>|null */
    private ?array $panels = null;

    private bool $loaded = false;

    /** What discovery looked like when the manifest was written, if recorded. */
    private ?string $fingerprint = null;

    public function __construct(private readonly PanelDiscoverer $discoverer) {}

    /**
     * Beside the config, route and event caches.
     *
     * Through `bootstrapPath()` rather than `base_path('bootstrap/...')`,
     * because an application may move that directory — and a manifest written
     * somewhere `optimize:clear` does not look is a stale cache nobody can
     * clear.
     */
    public static function path(): string
    {
        return app()->bootstrapPath('cache/panels.php');
    }

    public function exists(): bool
    {
        return File::exists(self::path());
    }

    /**
     * The classes for one panel, from the manifest when it exists and from
     * discovery otherwise.
     *
     * @return array{resources: list<string>, pages: list<string>, widgets: list<string>}
     */
    public function for(Panel $panel): array
    {
        $cached = $this->load()[$panel->getId()] ?? null;

        if ($cached !== null) {
            return $cached;
        }

        return [
            'resources' => $this->discoverer->resources($panel),
            'pages' => $this->discoverer->pages($panel),
            'widgets' => $this->discoverer->widgets($panel),
        ];
    }

    /**
     * Builds the manifest for every registered panel and writes it
     * atomically, so a half-written file can never be loaded.
     *
     * @return array<string, array{resources: list<string>, pages: list<string>, widgets: list<string>}>
     */
    public function write(PanelRegistry $registry): array
    {
        $manifest = [];

        foreach ($registry->all() as $panel) {
            $manifest[$panel->getId()] = [
                'resources' => $this->merge($panel->getResources(), $this->discoverer->resources($panel)),
                'pages' => $this->merge($panel->getPages(), $this->discoverer->pages($panel)),
                'widgets' => $this->merge($panel->getWidgets(), $this->discoverer->widgets($panel)),
            ];
        }

        File::ensureDirectoryExists(dirname(self::path()));

        $temporary = self::path().'.'.getmypid().'.tmp';

        File::put($temporary, $this->render([
            'panels' => $manifest,
            // What discovery would have found, so a later boot can notice
            // that it would now find something else — see
            // `DiscoveryFingerprint`.
            'fingerprint' => DiscoveryFingerprint::of(array_values($registry->all())),
        ]));
        File::move($temporary, self::path());

        $this->panels = $manifest;
        $this->loaded = true;

        return $manifest;
    }

    public function clear(): bool
    {
        $this->panels = null;
        $this->loaded = false;

        return $this->exists() ? File::delete(self::path()) : true;
    }

    /**
     * @return array<string, array{resources: list<string>, pages: list<string>, widgets: list<string>}>
     */
    private function load(): array
    {
        if ($this->loaded) {
            return $this->panels ?? [];
        }

        $this->loaded = true;

        if (! $this->exists()) {
            return $this->panels = [];
        }

        $manifest = require self::path();

        if (! is_array($manifest)) {
            return $this->panels = [];
        }

        // A manifest written before the fingerprint existed is a flat map of
        // panel id to classes. Reading both shapes means an upgrade does not
        // need a cache clear to boot.
        if (isset($manifest['panels']) && is_array($manifest['panels'])) {
            $this->fingerprint = is_string($manifest['fingerprint'] ?? null)
                ? $manifest['fingerprint']
                : null;

            /** @var array<string, array{resources: list<string>, pages: list<string>, widgets: list<string>}> $panels */
            $panels = $manifest['panels'];

            return $this->panels = $panels;
        }

        /** @var array<string, array{resources: list<string>, pages: list<string>, widgets: list<string>}> $manifest */
        return $this->panels = $manifest;
    }

    /**
     * Says so when the manifest no longer matches what is on disk.
     *
     * Development only, and only when a manifest exists at all — which in
     * development is the unusual case, so this normally costs nothing. When it
     * does exist, the cost is a `stat` per PHP file under the discovery paths,
     * against a failure whose symptom is a resource that is simply absent:
     * no route, no sidebar entry, no error, and no way to guess that
     * `panel:clear` is the answer.
     */
    public function warnIfStale(PanelRegistry $registry): void
    {
        if (! $this->exists()) {
            return;
        }

        // Never in production, where the manifest is the authority and
        // nothing should touch the filesystem. `testing` counts as
        // development: a suite that caches panels and then adds a resource has
        // exactly the problem this reports.
        if (! app()->hasDebugModeEnabled() && ! app()->environment('local', 'testing')) {
            return;
        }

        $this->load();

        if (! DiscoveryFingerprint::isStale(array_values($registry->all()), $this->fingerprint)) {
            return;
        }

        Log::warning(
            '[panel] The cached panel manifest is out of date: the classes under the discovery '
                .'paths have changed since `php artisan panel:cache` last ran. Until you run '
                .'`php artisan panel:clear`, anything added since then is invisible — no route, '
                .'no navigation entry, and no error to say so.'
        );
    }

    /**
     * @param  list<string>  $explicit
     * @param  list<string>  $discovered
     * @return list<string>
     */
    private function merge(array $explicit, array $discovered): array
    {
        $merged = array_values(array_unique([...$explicit, ...$discovered]));
        sort($merged);

        return $merged;
    }

    /**
     * `var_export` rather than serialization, so the file is a plain
     * `return [...]` that opcache can hold and a human can read.
     *
     * @param  array<string, array<string, list<string>>>  $manifest
     */
    /**
     * @param  array{panels: array<string, array{resources: list<string>, pages: list<string>, widgets: list<string>}>, fingerprint: string}  $manifest
     */
    private function render(array $manifest): string
    {
        return '<?php'.PHP_EOL.PHP_EOL
            .'// Generated by "php artisan panel:cache". Do not edit.'.PHP_EOL.PHP_EOL
            .'return '.var_export($manifest, true).';'.PHP_EOL;
    }
}
