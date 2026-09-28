<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PandaPanel\Support\FrontendContract;
use PandaPanel\Support\Installer\AssetManifest;
use PandaPanel\Support\Installer\PublishedAssets;

/**
 * Tells an application which published files are behind, which it has edited,
 * and which of the two are both.
 *
 * ## The problem
 *
 * The panel's frontend is published into the application rather than imported
 * from the package. That is the right trade — the component registries are
 * build-time `import.meta.glob` allowlists over the application's own tree,
 * and a component you cannot read the source of is one you cannot debug — but
 * it costs the thing every published-asset design costs: **a package update
 * cannot improve a file the application now owns.**
 *
 * `vendor:publish` alone cannot help, because it has only two settings and
 * both are wrong on an upgrade. Without `--force` it skips every file that
 * exists, so nothing updates. With `--force` it overwrites everything,
 * including the files the project deliberately changed. Neither can tell the
 * difference: "differs from the package's copy" is equally true of a stale
 * file and an edited one.
 *
 * ## The fix
 *
 * `AssetManifest` records what each file looked like *when it was published*.
 * That third value is the common ancestor, and it turns an ambiguous
 * two-way comparison into an unambiguous three-way one — the same move
 * `git merge-base` makes. See that class for the full table.
 *
 * Only two categories are ever written automatically, and in both the
 * application demonstrably has no opinion about the file: one it never had,
 * and one it has never touched. A file edited on both sides is reported with
 * its path and left exactly as it is, because resolving that by guessing is
 * how an upgrade eats somebody's work.
 *
 * ## Translations too, once they are published
 *
 * The frontend is most of what this reports on, but not all of it. An
 * application that published `lang/vendor/panda-panel` to reword a
 * confirmation owns those files the same way, and had the same problem: the
 * copy was frozen at the release it was published from, and every sentence
 * the package improved afterwards stopped at the vendor directory.
 *
 * `PublishedAssets::files()` includes them from the moment that directory
 * exists, so they arrive here with the same seven statuses and the same two
 * rules about which ones may be written. An application that never published
 * them has nothing to report: it reads the package's own copies and is
 * already current by definition.
 */
final class PanelAssetsCommand extends Command
{
    protected $signature = 'panel:assets
        {--update : Write the files that are safe to write}
        {--force : Also overwrite files this application has edited}
        {--reconciled=* : Record these already-merged files as level with the package copy, without changing them}';

    protected $description = 'Report which published panel assets are out of date, and update the ones that are safe to';

    /**
     * How each status reads, and whether `--update` acts on it.
     *
     * @var array<string, array{label: string, colour: string, writes: bool}>
     */
    private const STATUSES = [
        AssetManifest::NEW => ['label' => 'new', 'colour' => 'green', 'writes' => true],
        AssetManifest::STALE => ['label' => 'out of date', 'colour' => 'yellow', 'writes' => true],
        AssetManifest::CONFLICT => ['label' => 'CONFLICT', 'colour' => 'red', 'writes' => false],
        AssetManifest::MODIFIED => ['label' => 'yours', 'colour' => 'blue', 'writes' => false],
        AssetManifest::DELETED => ['label' => 'deleted by you', 'colour' => 'gray', 'writes' => false],
        AssetManifest::REMOVED_UPSTREAM => ['label' => 'no longer shipped', 'colour' => 'gray', 'writes' => false],
        AssetManifest::CURRENT => ['label' => 'current', 'colour' => 'gray', 'writes' => false],
    ];

    public function handle(): int
    {
        // Before the report, not after: a file somebody merged and is telling
        // us about is no longer in conflict, and every decision below — what
        // the summary counts, what `--force` is willing to overwrite — has to
        // be made against that answer rather than the question.
        $this->reconcile();

        $report = AssetManifest::compare();

        $counts = array_count_values(array_column($report, 'status'));

        if (! AssetManifest::exists()) {
            $this->components->warn(
                'No .panel-assets.json, so there is no record of what this application published. '
                .'Everything already identical to the package reads as current; anything else reads as new. '
                .'Run --update to write one.',
            );
            $this->newLine();
        }

        $this->contractDrift();

        $this->summary($counts);

        $updating = $this->option('update') || $this->option('force');
        $written = $updating ? $this->update($report) : [];

        $this->detail($report);

        if ($updating) {
            // Re-read from disk: the manifest records what the application
            // *has*, so it has to be written after the files, not from what
            // we intended to write.
            //
            // Written even when nothing was, because recording the state is
            // half of what an update is for. `vendor:publish` cannot write
            // this file, so an application that published by tag and then
            // edited a line has an unrecorded edit — and an unrecorded edit
            // reads as `new` on the next release and is overwritten. One
            // `--update` on a tree that is already current is what turns
            // those into `modified`, which is a file this command will not
            // touch.
            AssetManifest::write(AssetManifest::read());
        }

        if ($written !== []) {
            $this->newLine();
            $this->components->info(sprintf(
                'Wrote %d file(s).%s',
                count($written),
                $this->needsRebuild($written) ? ' Run `npm run build`.' : '',
            ));
        } elseif ($updating) {
            $this->newLine();
            $this->components->info(sprintf(
                'Nothing to write. Recorded the current state in %s.',
                PublishedAssets::relative(AssetManifest::path()),
            ));
        }

        if (! $this->option('update') && ! $this->option('force') && $this->writable($counts) > 0) {
            $this->newLine();
            $this->components->info('Run `php artisan panel:assets --update` to write the safe ones.');
        }

        // A conflict is not a failure of this command — it ran correctly and
        // found something a person has to look at. Reporting it as a non-zero
        // exit would break a deploy over a file somebody edited on purpose.
        return self::SUCCESS;
    }

    /**
     * Says outright when the published frontend is a protocol behind.
     *
     * The file-by-file report below cannot say this. It says *which* files
     * differ, which is most of them after any release and almost never worth
     * acting on — so the one case that is gets lost in the list. A frontend
     * behind on the contract is the case where features are missing rather
     * than merely older: a prop nothing reads, an endpoint nothing calls, a
     * payload the components reject. None of it logs anything.
     *
     * Said before the summary rather than after, because it is the reason to
     * read the summary at all.
     */
    private function contractDrift(): void
    {
        if (! FrontendContract::isDrifted()) {
            return;
        }

        $this->components->warn(sprintf(
            'The published frontend speaks contract v%d; this backend speaks v%d. '
                .'Anything added since v%d is missing or silently inert until the '
                .'components are republished.',
            (int) FrontendContract::published(),
            FrontendContract::expected(),
            (int) FrontendContract::published(),
        ));
        $this->newLine();
    }

    /**
     * Records files a person has merged by hand as level with the package.
     *
     * The missing step in the upgrade story. A `conflict` says both sides
     * changed and there is no answer this command can compute; `--force`
     * resolves it by discarding the application's work, which is not a merge.
     * Once somebody has actually merged the two copies, the content is theirs
     * and the ancestor is the package version they merged against — and
     * without a way to say so, the file stays in conflict forever and every
     * later run repeats a question that has been answered.
     *
     * Paths, always, and never an option that clears every conflict at once:
     * the state exists to make somebody read a diff.
     */
    private function reconcile(): void
    {
        /** @var list<string> $paths */
        $paths = $this->option('reconciled');

        if ($paths === []) {
            return;
        }

        $recorded = AssetManifest::reconcile($paths);

        foreach ($recorded as $relative) {
            $this->components->twoColumnDetail($relative, '<fg=green>reconciled</>');
        }

        foreach (array_diff($paths, $recorded) as $unknown) {
            $this->components->error(sprintf(
                '%s is not a file this package publishes, so there is nothing to reconcile.',
                $unknown,
            ));
        }

        if ($recorded !== []) {
            $this->newLine();
            $this->components->info(sprintf(
                'Recorded %d file(s) as level with the package copy. Their contents were not changed.',
                count($recorded),
            ));
            $this->newLine();
        }
    }

    /**
     * @param  array<string, array{status: string, destination: string, source: string|null}>  $report
     * @return list<string> the application-relative paths written
     */
    private function update(array $report): array
    {
        $written = [];

        foreach ($report as $relative => $entry) {
            $writes = self::STATUSES[$entry['status']]['writes'] ?? false;

            // `--force` extends to files this application edited, and to
            // nothing else: a file deleted on purpose stays deleted, and one
            // the package no longer ships is not resurrected.
            if ($this->option('force') && $entry['status'] === AssetManifest::CONFLICT) {
                $writes = true;
            }

            if ($this->option('force') && $entry['status'] === AssetManifest::MODIFIED) {
                $writes = true;
            }

            if (! $writes || $entry['source'] === null) {
                continue;
            }

            File::ensureDirectoryExists(dirname($entry['destination']));
            File::copy($entry['source'], $entry['destination']);

            $this->components->twoColumnDetail($relative, '<fg=green>written</>');

            $written[] = $relative;
        }

        return $written;
    }

    /**
     * Whether any of what was written is something Vite has to compile.
     *
     * A run that only refreshed `lang/vendor/panda-panel` needs no build, and
     * telling somebody to run one anyway is how a command's advice stops
     * being read.
     *
     * @param  list<string>  $written
     */
    private function needsRebuild(array $written): bool
    {
        $translations = array_map(
            static fn (string $destination): string => PublishedAssets::relative($destination),
            array_keys(PublishedAssets::translationFiles()),
        );

        return array_diff($written, $translations) !== [];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function summary(array $counts): void
    {
        foreach (self::STATUSES as $status => $meta) {
            $count = $counts[$status] ?? 0;

            if ($count === 0) {
                continue;
            }

            $this->components->twoColumnDetail(
                $meta['label'],
                sprintf('<fg=%s>%d</>', $meta['colour'], $count),
            );
        }
    }

    /**
     * Lists the files that need a human, and only those.
     *
     * `current` files are the overwhelming majority and saying so 300 times
     * is how a report becomes something nobody reads.
     *
     * @param  array<string, array{status: string, destination: string, source: string|null}>  $report
     */
    private function detail(array $report): void
    {
        $conflicts = array_keys(array_filter(
            $report,
            static fn (array $entry): bool => $entry['status'] === AssetManifest::CONFLICT,
        ));

        if ($conflicts === []) {
            return;
        }

        $this->newLine();
        $this->components->warn(sprintf(
            '%d file(s) changed both here and upstream. Neither copy is safe to throw away, so '
            .'nothing was written. Diff each against the package copy under '
            .'vendor/chocoalano/panel. Merge the two and re-run with '
            .'--reconciled=<path> to keep your copy, or --force to take the package\'s:',
            count($conflicts),
        ));
        $this->newLine();

        foreach ($conflicts as $path) {
            $this->line('  '.$path);
        }
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function writable(array $counts): int
    {
        return ($counts[AssetManifest::NEW] ?? 0) + ($counts[AssetManifest::STALE] ?? 0);
    }
}
