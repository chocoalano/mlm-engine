<?php

declare(strict_types=1);

namespace PandaPanel\Plugins;

use Composer\InstalledVersions;
use Throwable;

/**
 * What a plugin says about itself, and what it needs.
 *
 * A plugin is code somebody else wrote that reaches into a panel and adds
 * resources, pages, widgets and routes to it. When one breaks, the two
 * questions are always the same — *which* plugin, and *which version of it* —
 * and neither is answerable from a class name.
 *
 * The version is read from composer's own installed-packages data rather than
 * declared by hand. A hand-written version string is a string somebody forgets
 * to change, and a plugin reporting 1.2.0 while 1.4.1 is installed is worse
 * than a plugin reporting nothing.
 *
 * `requiresPanel` is a composer-style constraint against **this framework**,
 * checked when the plugin registers. It is what turns "the plugin exposes a
 * method the panel no longer has" from a fatal error deep inside a request
 * into a sentence naming the plugin and the version it wanted.
 */
final readonly class PluginMetadata
{
    /**
     * @param  string  $name  human-readable, for a report a person reads
     * @param  string|null  $package  the composer package, for the version lookup
     * @param  string|null  $requiresPanel  a constraint on this framework, e.g. `^1.2`
     * @param  string|null  $url  where to read about it
     */
    public function __construct(
        public string $name,
        public ?string $package = null,
        public ?string $requiresPanel = null,
        public ?string $url = null,
    ) {}

    /**
     * The installed version of the package this plugin ships in.
     *
     * Null for a plugin that lives in the application rather than in a
     * package of its own, which is a normal arrangement — a project's own
     * plugin is versioned by the project.
     */
    public function version(): ?string
    {
        if ($this->package === null) {
            return null;
        }

        try {
            return InstalledVersions::getPrettyVersion($this->package);
        } catch (Throwable) {
            // Named a package composer has never heard of. Reported as
            // "unknown" rather than raised: a wrong package name in metadata
            // is a documentation bug, not a reason to refuse to boot.
            return null;
        }
    }

    /**
     * @return array{name: string, package: string|null, version: string|null, requiresPanel: string|null, url: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'package' => $this->package,
            'version' => $this->version(),
            'requiresPanel' => $this->requiresPanel,
            'url' => $this->url,
        ];
    }
}
