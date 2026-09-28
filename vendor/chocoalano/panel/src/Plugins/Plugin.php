<?php

declare(strict_types=1);

namespace PandaPanel\Plugins;

use Illuminate\Support\Str;
use PandaPanel\Contracts\PanelPlugin;
use PandaPanel\Core\Panel;

/**
 * A convenient base for a plugin.
 *
 * Supplies the id from the class name, a no-op `boot()`, and a no-op
 * `publishes()`, so the common case — a bundle of resources and pages — is
 * one method.
 *
 * Implementing `PanelPlugin` directly is equally valid and is what a plugin
 * shipped as its own package should do: a package that extends an application
 * class is a package coupled to it. Nothing in this framework asks for a
 * `Plugin`; every lookup, every hook and `panel:publish` all go through the
 * contract.
 */
abstract class Plugin implements PanelPlugin
{
    public function id(): string
    {
        return Str::kebab(Str::beforeLast(class_basename(static::class), 'Plugin'));
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * The plugin's own name, and nothing it has not been told.
     *
     * A title-cased id is a reasonable name for a plugin that never says one,
     * and no package means no version — which is correct for a plugin that
     * lives in the application rather than in a package of its own.
     *
     * A plugin shipped as a package overrides this and names the package, so
     * that a report can say which version is installed:
     *
     *   public function metadata(): PluginMetadata
     *   {
     *       return new PluginMetadata(
     *           name: 'Billing',
     *           package: 'acme/panda-billing',
     *           requiresPanel: '^1.2',
     *       );
     *   }
     */
    public function metadata(): PluginMetadata
    {
        return new PluginMetadata(name: Str::headline($this->id()));
    }

    /**
     * Where this plugin's publishable assets live, if it has any.
     *
     * A real path on disk, resolved by the package that owns it — which is
     * why it is a method rather than configuration: only the plugin knows
     * where its own files are.
     *
     * @return array<string, string> source path => destination path
     */
    public function publishes(): array
    {
        return [];
    }

    /**
     * This plugin, as it is configured on a given panel.
     *
     * The reverse of `Panel::plugin()`, and the shape a resource supplied by
     * a plugin uses to read the settings it was installed with:
     *
     *   $currency = BillingPlugin::in(panel())?->currency() ?? 'usd';
     *
     * Null rather than a throw for a panel that does not have it: a resource
     * shared between two panels, installed in one of them, is a normal
     * arrangement and not a mistake.
     *
     * Found by class rather than by looking up `id()`, because reading `id()`
     * would mean constructing a plugin to ask it — and a plugin whose
     * constructor takes its configuration cannot be constructed without it.
     */
    public static function in(?Panel $panel): ?static
    {
        foreach ($panel?->getPlugins() ?? [] as $plugin) {
            if ($plugin instanceof static) {
                return $plugin;
            }
        }

        return null;
    }
}
