<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class MakePanelCommand extends PanelGeneratorCommand
{
    protected $signature = 'make:panel {name : The panel name, for example Admin} {--path= : The URL prefix, defaults to the kebab-cased name} {--force}';

    protected $description = 'Create a panel provider and its directories';

    public function handle(): int
    {
        $panel = $this->panelName((string) $this->argument('name'));
        $path = (string) ($this->option('path') ?? Str::kebab($panel));

        $this->writeStub('panel-provider', app_path("Panels/{$panel}/{$panel}PanelProvider.php"), [
            'panel' => $panel,
            'path' => $path,
        ]);

        // Discovery scans these, and an empty directory is not tracked by
        // Git, so the provider would point at paths that vanish on clone.
        foreach (['Resources', 'Pages', 'Widgets'] as $directory) {
            $target = app_path("Panels/{$panel}/{$directory}/.gitkeep");

            if (! File::exists($target)) {
                File::ensureDirectoryExists(dirname($target));
                File::put($target, '');
            }
        }

        $status = $this->report();

        // Registration is an explicit edit rather than a silent one: the
        // configured list is where panel order is visible, and order decides
        // where a user lands when the request does not name a panel.
        $this->components->info(sprintf(
            "Add App\\Panels\\%s\\%sPanelProvider::class to 'panels' in config/panda-panel.php.",
            $panel,
            $panel,
        ));

        return $status;
    }
}
