<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Support\Str;
use PandaPanel\Support\FrontendPaths;

final class MakePanelPageCommand extends PanelGeneratorCommand
{
    protected $signature = 'make:panel-page {name : The page class name} {--panel= : The panel it belongs to} {--component : Also generate a Vue component for the page} {--force}';

    protected $description = 'Create a standalone panel page';

    public function handle(): int
    {
        $panel = $this->panelName((string) $this->option('panel'));

        if ($panel === '') {
            $this->components->error('The --panel option is required.');

            return self::FAILURE;
        }

        $class = Str::studly((string) $this->argument('name'));

        // Without --component the page uses the generic renderer, so a page
        // with nothing bespoke to draw needs no Vue file at all.
        $component = $this->option('component')
            ? "Panels/{$panel}/Pages/{$class}"
            : 'panel/Page';

        $this->writeStub('page', app_path("Panels/{$panel}/Pages/{$class}.php"), [
            'panel' => $panel,
            'class' => $class,
            'component' => $component,
        ]);

        if ($this->option('component')) {
            $this->writeStub(
                'page-component',
                FrontendPaths::pages("{$panel}/Pages/{$class}.vue"),
                [],
            );
        }

        return $this->report();
    }
}
