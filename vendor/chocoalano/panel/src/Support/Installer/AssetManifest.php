<?php

declare(strict_types=1);

namespace PandaPanel\Support\Installer;

use Illuminate\Support\Facades\File;

/**
 * What the application got, when it published, so an upgrade can tell an edit
 * from a stale copy.
 *
 * ## The problem this solves
 *
 * The panel's frontend is published into the application rather than imported
 * from the package, and that is the right trade — a component you cannot see
 * the source of is a component you cannot debug, and the build-time
 * `import.meta.glob` registries require it. But it costs something real, and
 * the cost is the whole of this class: **once a file is the application's, a
 * package update cannot improve it.**
 *
 * With no record of what was published, an upgrade has two options and both
 * are bad. Re-publishing without `--force` skips every file, so nothing
 * updates. Re-publishing *with* `--force` overwrites everything, including the
 * files the project deliberately changed. Neither can tell the difference,
 * because "the file on disk differs from the package's" is true in both cases.
 *
 * ## Three hashes, not two
 *
 * The missing piece is the same one `git merge-base` supplies: a record of the
 * common ancestor. With the hash the file had *when it was published*, three
 * values answer the question exactly:
 *
 * | on disk | in package | means | what an update does |
 * | --- | --- | --- | --- |
 * | = manifest | = manifest | unchanged both sides | nothing |
 * | = manifest | ≠ manifest | stale, never edited | **overwrite, safely** |
 * | ≠ manifest | = manifest | you edited it, nothing upstream | leave alone |
 * | ≠ manifest | ≠ manifest | **conflict** — both changed | report, never touch |
 * | absent | present | you deleted it | leave alone |
 * | absent, not in manifest | present | new since you published | **write it** |
 * | present, not in manifest, differs | present | we have no record of it | report, never touch |
 * | in manifest | absent | no longer shipped | report as removable |
 *
 * Only two rows are written automatically, and both are ones where the
 * application demonstrably has no opinion about the file: one it has never
 * touched, and one it does not have at all. A conflict is reported with its
 * path and never resolved by guessing — that is a diff for a person to read.
 *
 * Note the second-to-last row, which is the one that reads oddly. A file on
 * disk that this manifest has never heard of is *not* new. It may be an
 * unrecorded copy of ours, it may be the application's own file at a name we
 * happen to ship too — the flat translation layout makes that ordinary — and
 * from here the two are indistinguishable. `new` used to cover it, and an
 * update overwrote a real project's own `lang/id/formats.php` because of it.
 *
 * ## What the recorded hash is
 *
 * The ancestor, and an ancestor is a point on the *package's* history: the
 * version of the package's copy that the application's copy was last brought
 * level with. It is never a hash of the application's own content.
 *
 * Getting that backwards is what PP-41 was. `write()` hashed the copy on disk,
 * so an update recorded a locally modified file's own edit as its ancestor.
 * The package's copy then no longer matched the ancestor while the disk copy
 * did — which is the definition of `stale`, the one state an update overwrites
 * without asking. Every deliberate customisation survived exactly one release,
 * and a `conflict` was quietly downgraded to a file safe to throw away.
 *
 * So the ancestor moves on exactly two occasions, and both are decisions:
 * a file was overwritten from the package, or `reconcile()` was told by name
 * that somebody merged it. An update that touched nothing records nothing.
 *
 * ## Getting out of a conflict
 *
 * `--force` resolves a conflict by discarding the application's copy, which is
 * an answer but not a merge. `reconcile()` is the other one: the person merges
 * the two copies themselves, then names the file, and the ancestor moves to
 * the package version they merged against while the content stays theirs. The
 * file reads as `modified` afterwards — ours, and level with upstream.
 *
 * ## Where it lives
 *
 * `.panel-assets.json` at the application's root, and it belongs in the
 * application's repository: it is a record of a decision the project made, in
 * the same way `composer.lock` is. Under `bootstrap/cache` it would be
 * regenerated and useless; under `storage` it would be gitignored and lost on
 * the first deploy.
 *
 * ## What it covers
 *
 * Everything `PublishedAssets::files()` lists: the frontend always, and the
 * translations from the moment an application publishes them to
 * `lang/vendor/panda-panel`. Both are files the application owns once they
 * are on disk, and both had the same failure without a record — a reworded
 * confirmation frozen at the release it was published from is the same bug as
 * a stale component, and reads exactly as "the package never fixed that".
 *
 * A translation that was never published is not tracked and does not need to
 * be: the package's own copy is what the translator reads, so it cannot fall
 * behind.
 */
final class AssetManifest
{
    /** A file the application does not have. Nothing to lose by writing it. */
    public const NEW = 'new';

    /** Published, untouched, and unchanged upstream. */
    public const CURRENT = 'current';

    /** Published, untouched, and changed upstream. Safe to overwrite. */
    public const STALE = 'stale';

    /** Published and then edited here. Nothing new upstream. */
    public const MODIFIED = 'modified';

    /**
     * A person has to look. Either edited here *and* changed upstream, or on
     * disk with nothing recorded about it and differing from ours — which
     * could be our copy edited, or a file of the application's own at a name
     * this package also ships to.
     */
    public const CONFLICT = 'conflict';

    /** Published and then deleted here. */
    public const DELETED = 'deleted';

    /** Published once, and the package no longer ships it. */
    public const REMOVED_UPSTREAM = 'removed-upstream';

    public static function path(): string
    {
        return base_path('.panel-assets.json');
    }

    public static function exists(): bool
    {
        return File::exists(self::path());
    }

    /**
     * The recorded hashes, keyed by application-relative destination.
     *
     * A manifest that cannot be parsed is treated as absent rather than
     * fatal: the worst outcome is that every file reads as `new`, which is
     * exactly the state an application that never published is in.
     *
     * @return array<string, string>
     */
    public static function read(): array
    {
        if (! self::exists()) {
            return [];
        }

        $decoded = json_decode((string) File::get(self::path()), associative: true);

        if (! is_array($decoded) || ! is_array($decoded['files'] ?? null)) {
            return [];
        }

        $files = [];

        foreach ($decoded['files'] as $path => $hash) {
            if (is_string($path) && is_string($hash)) {
                $files[$path] = $hash;
            }
        }

        return $files;
    }

    /**
     * Records which **package** version each published file is reconciled with.
     *
     * The recorded hash is the ancestor, and an ancestor is only meaningful as
     * a point on the package's history — the version the application's copy
     * was last brought level with. It is never the application's own content.
     *
     * That distinction is the whole of PP-41. This used to hash the copy on
     * disk for every file, so an update recorded a locally modified file's own
     * edit as its ancestor. The file then read as `stale` on the next run — the
     * package's copy no longer matches an ancestor that is now the edit — and
     * `stale` is the one state an update overwrites without asking. Every
     * deliberate customisation survived exactly one release, and a conflict was
     * quietly downgraded to a file safe to throw away.
     *
     * So a file is recorded against the package only when the application
     * demonstrably has the package's copy:
     *
     * | on disk | recorded as | why |
     * | --- | --- | --- |
     * | identical to the package | the package's hash | reconciled, by having the same bytes |
     * | differs, nothing recorded | its own hash | no ancestor exists to preserve |
     * | differs, ancestor recorded | the ancestor, untouched | ours; this run did not reconcile it |
     * | absent, ancestor recorded | the ancestor, untouched | deleted on purpose, and it stays deleted |
     *
     * Row three is what a `modified` and a `conflict` file take, and it is why
     * an update can be run as often as you like without moving anything the
     * application owns. Row four used to drop the record, which turned a file
     * deleted on purpose into a `new` one and wrote it straight back.
     *
     * A file that was just overwritten from the package — `stale`, `new`, or a
     * `--force`d conflict — is identical to the package by the time this runs,
     * so it takes row one and rebases onto the version it just received. That
     * is the only way an ancestor moves on its own; every other move is
     * `reconcile()`, which a person asks for by name.
     *
     * @param  array<string, string>  $existing  hashes to keep for files not in the map
     * @param  array<string, string>|null  $shipped  destination => source, defaulting to the real publish map
     */
    public static function write(array $existing = [], ?array $shipped = null): void
    {
        $files = $existing;

        foreach ($shipped ?? PublishedAssets::files() as $destination => $source) {
            $relative = PublishedAssets::relative($destination);
            $recorded = $files[$relative] ?? null;

            if (! File::exists($destination)) {
                // Deleted here. The ancestor is what keeps it reading as
                // `deleted` rather than as a file we have never heard of.
                if ($recorded === null) {
                    unset($files[$relative]);
                }

                continue;
            }

            $onDisk = self::hash($destination);

            if ($onDisk === self::hash($source)) {
                $files[$relative] = $onDisk;

                continue;
            }

            if ($recorded === null) {
                // Nothing to preserve. Claiming the package's hash here would
                // call the file stale and overwrite it on the next run.
                $files[$relative] = $onDisk;
            }
        }

        self::writeFiles($files);
    }

    /**
     * @param  array<string, string>  $files
     */
    private static function writeFiles(array $files): void
    {
        ksort($files);

        File::put(self::path(), json_encode([
            '_' => 'Written by php artisan panel:install / panel:assets. Commit this file: '
                .'it is the record of which version of the panel frontend and translations this '
                .'application published, and without it an upgrade cannot tell your edits from a '
                .'stale copy. Each hash is the package version that copy is reconciled with, not '
                .'the contents of your copy.',
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * Records named files as reconciled with the package's current copy,
     * leaving their contents alone.
     *
     * The way out of a `conflict` that keeps both sides. A conflict says the
     * application edited a file and the package changed it too, and there is
     * no automatic answer to that — `--force` resolves it by throwing the
     * application's work away, which is the opposite of a merge. Once a person
     * has actually merged the two, the content is theirs and the ancestor is
     * the package version they merged against; this records the second half.
     * The file reads as `modified` afterwards, which is what "ours, and level
     * with upstream" looks like.
     *
     * Named files only, and never a sweep over everything in conflict. The
     * state exists to demand a diff be read, and an option that clears them
     * all at once is a way to not read any of them.
     *
     * @param  list<string>  $relatives  application-relative paths, as the report prints them
     * @param  array<string, string>|null  $shipped  destination => source, defaulting to the real publish map
     * @return list<string> the paths actually recorded; anything absent was not one we ship
     */
    public static function reconcile(array $relatives, ?array $shipped = null): array
    {
        $files = self::read();
        $recorded = [];

        foreach ($shipped ?? PublishedAssets::files() as $destination => $source) {
            $relative = PublishedAssets::relative($destination);

            if (! in_array($relative, $relatives, true)) {
                continue;
            }

            // A file that is not on disk has nothing to reconcile: there is no
            // merged copy to keep, and recording one would hide a deletion.
            if (! File::exists($destination)) {
                continue;
            }

            $files[$relative] = self::hash($source);
            $recorded[] = $relative;
        }

        if ($recorded !== []) {
            self::writeFiles($files);
        }

        return $recorded;
    }

    /**
     * Every shipped file, with what should happen to it.
     *
     * `$files` exists so the four states can be tested against a scratch
     * fixture. This repository is its own test application — its published
     * copies *are* the package's files — so with the real map, "on disk" and
     * "in the package" can never differ, and the two cases that matter most
     * would be untestable.
     *
     * @param  array<string, string>|null  $files  destination => source, defaulting to the real publish map
     * @return array<string, array{status: self::*, destination: string, source: string|null}>
     */
    public static function compare(?array $files = null): array
    {
        $manifest = self::read();
        $shipped = $files ?? PublishedAssets::files();
        $report = [];

        foreach ($shipped as $destination => $source) {
            $relative = PublishedAssets::relative($destination);
            $recorded = $manifest[$relative] ?? null;

            $report[$relative] = [
                'status' => self::statusFor($destination, $source, $recorded),
                'destination' => $destination,
                'source' => $source,
            ];
        }

        // Anything recorded that we no longer ship. Reported rather than
        // deleted: a file this package stopped shipping may well have been
        // adopted by the application in the meantime.
        foreach ($manifest as $relative => $hash) {
            if (! isset($report[$relative])) {
                $report[$relative] = [
                    'status' => self::REMOVED_UPSTREAM,
                    'destination' => base_path($relative),
                    'source' => null,
                ];
            }
        }

        ksort($report);

        return $report;
    }

    /**
     * @return self::*
     */
    private static function statusFor(string $destination, string $source, ?string $recorded): string
    {
        $onDisk = File::exists($destination) ? self::hash($destination) : null;
        $inPackage = self::hash($source);

        if ($recorded === null) {
            // Nothing recorded, so there is no ancestor and the answer comes
            // from what is on disk.
            //
            // Absent is the only case `new` may mean. A file that is *there*
            // and differs from ours is not new — it is a file we have no
            // record of, which is a different thing and the one that cost
            // somebody their work: an application's own `lang/id/formats.php`
            // at a path this package also publishes to was read as `new` and
            // overwritten on the first update after an upgrade. Nobody can
            // tell from here whether such a file is ours, theirs, or a merge
            // of both, and guessing is what this class exists not to do — so
            // it is a conflict, and a person decides with `--reconciled` or
            // `--force`.
            //
            // Identical is not ambiguous at all: `vendor:publish` leaves
            // exactly that, and it is current.
            return match (true) {
                $onDisk === null => self::NEW,
                $onDisk === $inPackage => self::CURRENT,
                default => self::CONFLICT,
            };
        }

        if ($onDisk === null) {
            return self::DELETED;
        }

        $edited = $onDisk !== $recorded;
        $updated = $inPackage !== $recorded;

        return match (true) {
            $edited && $updated => self::CONFLICT,
            $edited => self::MODIFIED,
            $updated => self::STALE,
            default => self::CURRENT,
        };
    }

    /**
     * Content hash, with line endings normalised.
     *
     * A checkout on Windows, or an editor configured to write CRLF, would
     * otherwise report every file as edited — and a report where everything
     * is a conflict is a report nobody reads.
     */
    private static function hash(string $path): string
    {
        return hash('xxh128', str_replace("\r\n", "\n", (string) File::get($path)));
    }
}
