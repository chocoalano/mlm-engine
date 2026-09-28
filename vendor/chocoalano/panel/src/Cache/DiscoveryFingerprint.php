<?php

declare(strict_types=1);

namespace PandaPanel\Cache;

use Illuminate\Support\Facades\File;
use PandaPanel\Core\Panel;
use SplFileInfo;
use Throwable;

/**
 * A cheap summary of what discovery would find, so a stale manifest can say
 * so.
 *
 * `panel:cache` writes a list of class names and discovery then never runs.
 * That is the whole point of it, and it is also the trap: a resource added
 * afterwards simply is not in the panel. No error, no empty state, no route —
 * the sidebar looks exactly as it did before, and the answer ("run
 * `php artisan panel:clear`") is unguessable from the symptom.
 *
 * The fingerprint is the count of PHP files under each discovery path and the
 * newest modification time among them. That is enough to notice a file added,
 * removed, or edited, and it is a `stat` per file rather than the reflection
 * and class loading real discovery does — the difference between the check
 * and the thing it is checking.
 *
 * **It is only ever computed in development.** In production the manifest is
 * the authority and nothing touches the filesystem, which is what the cache is
 * for. A deploy that adds a resource also runs `panel:cache`; a developer who
 * ran it once and forgot is the case this exists for.
 */
final class DiscoveryFingerprint
{
    /**
     * @param  list<Panel>  $panels
     */
    public static function of(array $panels): string
    {
        $parts = [];

        foreach ($panels as $panel) {
            $paths = [
                ...$panel->getResourceDiscoveryPaths(),
                ...$panel->getPageDiscoveryPaths(),
                ...$panel->getWidgetDiscoveryPaths(),
            ];

            // Sorted, so two panels declaring the same paths in a different
            // order do not read as a change.
            sort($paths);

            foreach ($paths as $path) {
                $parts[] = $panel->getId().':'.$path.':'.self::summarize($path);
            }
        }

        return hash('xxh128', implode('|', $parts));
    }

    /**
     * Whether the panel classes on disk have moved since the manifest was
     * written.
     *
     * False whenever the answer cannot be established — no manifest, no
     * fingerprint in it (one written by an older version), or a path that
     * cannot be read. Being unsure is not a reason to tell somebody their
     * cache is stale.
     *
     * @param  list<Panel>  $panels
     */
    public static function isStale(array $panels, ?string $recorded): bool
    {
        if ($recorded === null || $recorded === '') {
            return false;
        }

        try {
            return self::of($panels) !== $recorded;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * `count:newest-mtime` for one directory, or `missing` for a path that is
     * not there — which is itself a change worth noticing, because a renamed
     * directory is a panel that silently discovers nothing.
     */
    private static function summarize(string $path): string
    {
        if (! File::isDirectory($path)) {
            return 'missing';
        }

        $files = array_filter(
            File::allFiles($path),
            static fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
        );

        $newest = 0;

        foreach ($files as $file) {
            $newest = max($newest, (int) $file->getMTime());
        }

        return count($files).':'.$newest;
    }
}
