<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Support\Str;
use PandaPanel\Support\FrontendPaths;

final class MakePanelWidgetCommand extends PanelGeneratorCommand
{
    /** @var array<string, string> */
    private const TYPES = [
        'stats' => 'widget-stats',
        'table' => 'widget-table',
        'chart' => 'widget-chart',
        'custom' => 'widget-custom',
    ];

    protected $signature = 'make:panel-widget {name : The widget class name} {--panel= : The panel it belongs to} {--type=stats : stats, table, chart, or custom} {--force}';

    protected $description = 'Create a panel widget';

    public function handle(): int
    {
        $panel = $this->panelName((string) $this->option('panel'));

        if ($panel === '') {
            $this->components->error('The --panel option is required.');

            return self::FAILURE;
        }

        $type = (string) $this->option('type');

        if (! array_key_exists($type, self::TYPES)) {
            $this->components->error(sprintf(
                'Unknown widget type [%s]. Valid types are: %s.',
                $type,
                implode(', ', array_keys(self::TYPES)),
            ));

            return self::FAILURE;
        }

        $class = Str::studly((string) $this->argument('name'));
        $component = "Panels/{$panel}/Widgets/{$class}";

        $this->writeStub(self::TYPES[$type], app_path("Panels/{$panel}/Widgets/{$class}.php"), [
            'panel' => $panel,
            'class' => $class,
            'component' => $component,
        ]);

        // A custom widget without its component would render the fallback,
        // so the component is not optional for that type.
        if ($type === 'custom') {
            $this->writeStub(
                'widget-component',
                FrontendPaths::pages("{$panel}/Widgets/{$class}.vue"),
                ['label' => $class],
            );
        }

        return $this->report();
    }
}
