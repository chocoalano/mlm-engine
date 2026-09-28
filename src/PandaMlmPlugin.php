<?php

declare(strict_types=1);

namespace PandaBear\Mlm;

use PandaPanel\Contracts\PanelPlugin;
use PandaPanel\Core\Panel;
use PandaPanel\Plugins\PluginMetadata;

final class PandaMlmPlugin implements PanelPlugin
{
    public static function make(): self
    {
        return new self;
    }

    public function id(): string
    {
        return 'panda-mlm';
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public function metadata(): PluginMetadata
    {
        return new PluginMetadata(
            name: 'Panda MLM',
            package: 'pandabear/mlm',
            requiresPanel: '^0.5.7',
        );
    }

    /**
     * @return array<string, string>
     */
    public function publishes(): array
    {
        return [];
    }
}
