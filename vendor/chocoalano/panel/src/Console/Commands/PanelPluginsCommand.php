<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Console\Command;
use PandaPanel\Core\PanelManager;

/**
 * Lists what is installed, on which panel, at which version.
 *
 * The report a bug report should contain. A panel with four plugins has four
 * sources of resources, pages, widgets and routes, and when one of them
 * misbehaves the first two questions are always "which plugin" and "which
 * version" — neither of which is answerable from a stack trace naming this
 * framework.
 *
 * Versions come from composer's own installed-packages data rather than from
 * anything a plugin author remembered to bump, so a plugin reporting 1.4.1 is
 * on 1.4.1.
 */
final class PanelPluginsCommand extends Command
{
    protected $signature = 'panel:plugins {--panel= : Only this panel}';

    protected $description = 'List the plugins registered on each panel, with versions';

    public function handle(PanelManager $manager): int
    {
        $only = $this->option('panel');
        $rows = [];

        foreach ($manager->all() as $panel) {
            if (is_string($only) && $only !== '' && $only !== $panel->getId()) {
                continue;
            }

            foreach ($panel->getPlugins() as $id => $plugin) {
                $metadata = $plugin->metadata();

                $rows[] = [
                    $panel->getId(),
                    $id,
                    $metadata->name,
                    $metadata->package ?? '<fg=gray>in this application</>',
                    // "unknown" rather than blank: a plugin naming a package
                    // composer has never heard of is a different problem from
                    // one that names no package at all, and the two should not
                    // look the same.
                    $metadata->version() ?? '<fg=gray>unknown</>',
                    $metadata->requiresPanel ?? '<fg=gray>any</>',
                ];
            }
        }

        if ($rows === []) {
            $this->components->info('No plugins are registered.');

            return self::SUCCESS;
        }

        $this->table(['Panel', 'ID', 'Name', 'Package', 'Version', 'Requires'], $rows);

        return self::SUCCESS;
    }
}
