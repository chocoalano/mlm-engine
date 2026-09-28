<?php

declare(strict_types=1);

namespace PandaPanel\Contracts;

use PandaPanel\Core\Panel;
use PandaPanel\Plugins\PluginMetadata;

/**
 * A reusable bundle of panel configuration.
 *
 * Everything a plugin does, it does through the panel's own public API — it
 * registers resources, pages, widgets, assets, render hooks, and navigation
 * groups exactly as a panel provider would. There is no second configuration
 * surface, which is what stops a plugin from being able to do something a
 * panel cannot.
 *
 * ## The lifecycle, in the order it happens
 *
 * Three phases, and which one a piece of work belongs in is the single thing
 * plugin authors get wrong:
 *
 * | Phase         | When                                   | What belongs there |
 * | ------------- | -------------------------------------- | ------------------ |
 * | `register()`  | while the panel is being configured    | resources, pages, widgets, navigation groups, settings |
 * | `boot()`      | after the panel is resolved, per request | anything needing the container, the user, or a URL |
 * | `publishes()` | never automatically — only `panel:publish` | files this plugin copies into the application |
 *
 * `register()` runs during the application's boot, for **every** request,
 * including the ones that never touch a panel. Work there that queries, reads
 * the authenticated user, or resolves a route is work every request pays for
 * and most requests waste — and, for the user, work done before there is a
 * user to read.
 *
 * `boot()` runs once the panel has been resolved for a request, and only for
 * requests that reached that panel. It also runs *before* the panel's own
 * `bootUsing()` callbacks, so an application always gets the last word over a
 * plugin it installed.
 *
 * Getting the two backwards is the usual plugin bug, and it is a quiet one:
 * a `register()` that queries works perfectly in development and shows up as
 * a database hit on every asset request in production.
 */
interface PanelPlugin
{
    /**
     * A stable name. Used to look the plugin up on a panel, so two plugins
     * cannot share one and a panel can be asked whether it has a given
     * plugin without knowing the class.
     *
     * Stable across versions: an application asking `hasPlugin('billing')`
     * is asking about the plugin, not about the release of it.
     */
    public function id(): string;

    /**
     * Configures the panel. Runs while it is being built.
     *
     * Nothing here may query, resolve a route, or read the current user —
     * there is no request yet, and this runs for every one of them.
     */
    public function register(Panel $panel): void;

    /**
     * Runs once the panel has been resolved for a request.
     *
     * Default is nothing, because most plugins are configuration and have
     * nothing to do here.
     */
    public function boot(Panel $panel): void;

    /**
     * What this plugin is, for a report a person reads.
     *
     * Optional in practice — the base class supplies a name from the id — but
     * on the contract because the two questions asked of a misbehaving plugin
     * are always "which one" and "which version", and neither is answerable
     * from a class name. A plugin shipped as a package names it here and gets
     * its version read from composer rather than restating it.
     *
     * `requiresPanel` is checked when the plugin registers, so a plugin built
     * against an older framework says so by name instead of failing deep
     * inside a request.
     */
    public function metadata(): PluginMetadata;

    /**
     * Files this plugin copies into the application, source => destination.
     *
     * On the contract rather than only on the `Plugin` base class, because a
     * plugin shipped as its own package should implement this interface
     * directly — a package that extends an application's class is a package
     * coupled to it — and `panel:publish` has to be able to ask *any* plugin
     * what it publishes, not just the ones that happened to inherit.
     *
     * A Vue component cannot be resolved from a plugin's own package: every
     * component registry in this framework is an `import.meta.glob` over the
     * application's tree, which is a build-time allowlist by design. So a
     * plugin publishes its components into that tree and from then on they
     * are the application's — in its repository, in its build, and editable.
     * That is a feature rather than a workaround; a component you cannot see
     * the source of is a component you cannot debug.
     *
     * Empty for a plugin that ships no files, which is most of them.
     *
     * @return array<string, string> absolute source path => absolute destination path
     */
    public function publishes(): array;
}
