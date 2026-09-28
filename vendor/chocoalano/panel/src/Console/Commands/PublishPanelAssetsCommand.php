<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PandaPanel\Contracts\PanelPlugin;
use PandaPanel\Core\PanelManager;

/**
 * Copies plugin assets into the application.
 *
 * A plugin that ships a Vue component cannot have it resolved from its own
 * package: every component registry in this framework is an
 * `import.meta.glob` over `resources/js/pages/Panels/**`, which is a
 * build-time allowlist by design — a name resolved from anywhere else would
 * be a name the build never saw.
 *
 * So a plugin publishes its components into that tree, and from then on they
 * are the application's files: in the repository, in the build, and editable.
 * That is a feature rather than a workaround. A component you cannot see the
 * source of is a component you cannot debug.
 */
final class PublishPanelAssetsCommand extends Command
{
    protected $signature = 'panel:publish
        {plugin? : Only this plugin}
        {--force : Overwrite files that already exist}';

    protected $description = 'Copy plugin assets into the application';

    public function handle(PanelManager $manager): int
    {
        $only = $this->argument('plugin');
        $published = 0;

        foreach ($manager->all() as $panel) {
            foreach ($panel->getPlugins() as $id => $plugin) {
                if (is_string($only) && $only !== $id) {
                    continue;
                }

                $published += $this->publish($id, $plugin);
            }
        }

        if ($published === 0) {
            $this->components->info('Nothing to publish.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Published %d file(s).', $published));

        return self::SUCCESS;
    }

    private function publish(string $id, PanelPlugin $plugin): int
    {
        // Through the contract, so a plugin shipped as its own package —
        // which should implement `PanelPlugin` directly rather than extend
        // this application's base class — publishes like any other.
        $published = 0;

        foreach ($plugin->publishes() as $source => $destination) {
            if (! File::exists($source)) {
                $this->components->warn(sprintf('[%s] %s does not exist.', $id, $source));

                continue;
            }

            $published += File::isDirectory($source)
                ? $this->publishDirectory($id, $source, $destination)
                : (int) $this->publishFile($id, $source, $destination);
        }

        return $published;
    }

    private function publishDirectory(string $id, string $source, string $destination): int
    {
        $published = 0;

        foreach (File::allFiles($source) as $file) {
            $target = $destination.'/'.$file->getRelativePathname();

            $published += (int) $this->publishFile($id, $file->getPathname(), $target);
        }

        return $published;
    }

    private function publishFile(string $id, string $source, string $destination): bool
    {
        // Never silently: a published file the plugin author changed is a
        // file the application may have changed too, and overwriting one of
        // those is losing work.
        if (File::exists($destination) && ! $this->option('force')) {
            $this->components->twoColumnDetail(
                sprintf('[%s] %s', $id, $destination),
                '<fg=yellow>exists, skipped</>',
            );

            return false;
        }

        File::ensureDirectoryExists(dirname($destination));
        File::copy($source, $destination);

        $this->components->twoColumnDetail(
            sprintf('[%s] %s', $id, $destination),
            '<fg=green>published</>',
        );

        return true;
    }
}
