<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Support\Facades\Artisan;
use PandaBear\Mlm\PandaMlmPlugin;
use PandaPanel\Contracts\PanelPlugin;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;

final class PandaMlmPluginTest extends TestCase
{
    public function test_it_implements_the_panel_plugin_contract(): void
    {
        $this->assertInstanceOf(PanelPlugin::class, PandaMlmPlugin::make());
    }

    public function test_its_id_is_panda_mlm(): void
    {
        // Applications branch on `hasPlugin('panda-mlm')`, so a rename is a
        // breaking change.
        $this->assertSame('panda-mlm', PandaMlmPlugin::make()->id());
    }

    public function test_its_metadata_names_the_plugin_and_its_package(): void
    {
        $metadata = PandaMlmPlugin::make()->metadata();

        $this->assertSame('Panda MLM', $metadata->name);
        $this->assertSame('pandabear/mlm', $metadata->package);
    }

    public function test_it_requires_the_panel_version_composer_requires(): void
    {
        $this->assertSame(
            $this->composerJson()['require']['chocoalano/panel'],
            PandaMlmPlugin::make()->metadata()->requiresPanel,
        );
    }

    public function test_it_publishes_nothing_yet(): void
    {
        $this->assertSame([], PandaMlmPlugin::make()->publishes());
    }

    public function test_it_registers_on_a_panel(): void
    {
        $plugin = PandaMlmPlugin::make();

        // `plugins()` checks `requiresPanel` against the installed framework
        // before calling `register()`. Panda Panel is installed here as a
        // tagged package, so that check runs rather than being skipped.
        $panel = Panel::make('mlm-register')->path('mlm-register')->plugins([$plugin]);

        $this->assertTrue($panel->hasPlugin('panda-mlm'));
        $this->assertSame($plugin, $panel->plugin('panda-mlm'));
    }

    public function test_it_boots_with_the_panel(): void
    {
        $reached = false;

        $panel = Panel::make('mlm-boot')
            ->path('mlm-boot')
            ->plugins([PandaMlmPlugin::make()])
            ->bootUsing(static function () use (&$reached): void {
                $reached = true;
            });

        $panel->boot();

        // Plugins boot before the panel's own callbacks, so reaching the
        // callback means the plugin booted without throwing.
        $this->assertTrue($reached);
    }

    public function test_panel_plugins_reports_it(): void
    {
        $this->app->make(PanelManager::class)->register(
            Panel::make('mlm-report')->path('mlm-report')->plugins([PandaMlmPlugin::make()]),
        );

        $this->assertSame(0, Artisan::call('panel:plugins', ['--panel' => 'mlm-report']));

        $output = Artisan::output();

        $this->assertStringContainsString('panda-mlm', $output);
        $this->assertStringContainsString('Panda MLM', $output);
        $this->assertStringContainsString('pandabear/mlm', $output);
        $this->assertStringContainsString($this->composerJson()['require']['chocoalano/panel'], $output);
    }
}
