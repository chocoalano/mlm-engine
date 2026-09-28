<?php

declare(strict_types=1);

namespace PandaPanel\Plugins;

use Composer\InstalledVersions;
use Composer\Semver\Semver;
use PandaPanel\Contracts\PanelPlugin;
use PandaPanel\Exceptions\PanelRegistrationException;
use Throwable;

/**
 * Refuses a plugin built against a version of this framework that no longer
 * exists.
 *
 * The failure this replaces is the reason it is worth having. A plugin
 * calling a panel method that was removed fails with `Call to undefined
 * method Panel::whatever()`, somewhere inside a request, naming the framework
 * rather than the plugin — and the person reading it has no way to know which
 * of their four plugins asked for it, or which version of that plugin they
 * are on.
 *
 * Checked at registration, which is the earliest moment the answer is knowable
 * and the last moment before the plugin starts changing the panel.
 *
 * ## When the check is skipped, and why that is right
 *
 * Three cases, all of which mean "there is no question to answer":
 *
 * - The plugin declared no constraint. Most plugins do not, and a plugin that
 *   has not thought about compatibility should not be treated as if it had.
 * - This framework is not installed as a composer package — which is exactly
 *   the situation in its own repository and test suite. There is no version
 *   to compare against, and inventing one would fail every plugin here.
 * - The version is a branch alias like `dev-main`. A constraint cannot be
 *   evaluated against a branch, and refusing every plugin on a development
 *   checkout would make the framework untestable against its own ecosystem.
 */
final class PluginCompatibility
{
    /**
     * This package, as composer knows it.
     *
     * Must match `name` in `composer.json` exactly. A name no installation
     * carries makes `InstalledVersions::getPrettyVersion()` throw, which this
     * class reads as "not installed as a package" and answers null to — and a
     * null version skips every `requiresPanel` constraint there is, silently
     * and for good. `PluginTest` compares the two so a rename cannot turn the
     * check off again.
     */
    private const PACKAGE = 'chocoalano/panel';

    /**
     * @param  string|null  $installed  the framework version to check against,
     *                                  defaulting to the one composer reports
     *
     * @throws PanelRegistrationException
     */
    public static function assert(PanelPlugin $plugin, string $panelId, ?string $installed = null): void
    {
        $constraint = $plugin->metadata()->requiresPanel;

        if ($constraint === null) {
            return;
        }

        $installed ??= self::installedVersion();

        if ($installed === null) {
            return;
        }

        if (Semver::satisfies($installed, $constraint)) {
            return;
        }

        throw PanelRegistrationException::incompatiblePlugin(
            $plugin->metadata()->name,
            $plugin->id(),
            $panelId,
            $constraint,
            $installed,
        );
    }

    /**
     * The installed version of this framework, or null when the question
     * cannot be answered.
     */
    private static function installedVersion(): ?string
    {
        try {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (Throwable) {
            // Not installed as a package: a path repository, a git checkout,
            // or this repository's own suite.
            return null;
        }

        // `dev-main` is a branch, which no constraint can be evaluated
        // against. `1.0.0+no-version-set` is composer's placeholder for a root
        // package that never declared one — which is every checkout of this
        // repository, and evaluating a constraint against it would refuse a
        // plugin requiring ^2.0 on a machine running whatever main is.
        if ($version === null
            || str_starts_with($version, 'dev-')
            || str_contains($version, 'no-version-set')) {
            return null;
        }

        return $version;
    }
}
