<?php

declare(strict_types=1);

namespace PandaPanel\Translation;

use Closure;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Filesystem\Filesystem;

/**
 * Lets the package's strings be overridden from `lang/{locale}` directly.
 *
 * Laravel has exactly one place it looks for a namespaced override:
 * `lang/vendor/{namespace}/{locale}/{group}.php`. That is where this package
 * used to publish, and it is a directory nobody edits by accident — but it is
 * also three levels away from where an application keeps every other sentence
 * it has written, and the ask was to have the panel's strings sit beside them:
 *
 *     lang/en/tables.php
 *     lang/id/tables.php
 *
 * Nothing in the framework reads that path for a namespace, so this does. It
 * wraps whatever loader the application is using — the framework's
 * `FileLoader` normally, somebody's database loader otherwise — asks it for
 * the lines first, and merges the application's file over the top.
 *
 * Merged key by key, `array_replace_recursive`, for the same reason Laravel
 * merges its own vendor overrides that way: a published copy that predates a
 * key added in a later release still gets that key from the package, so an
 * upgrade cannot leave a screen showing `panda-panel::tables.foo` because one
 * file on disk was written before the key existed.
 *
 * ## The cost of the flat layout
 *
 * `lang/en/actions.php` is a path an application may already be using for its
 * own strings. Where it is, the two files are the same file, and the panel's
 * `panda-panel::actions.*` keys and the application's `actions.*` keys live
 * side by side in it. That is the trade the flat layout makes; it is why the
 * merge is one-directional — this only ever *adds* to what the package
 * shipped, and an application's own non-namespaced lookups are untouched.
 *
 * Only the panel's namespace is affected, and within it only the groups the
 * package actually ships. Every other lookup — a plain `__('tables.…')`, or a
 * `panda-panel::` group that is not one of ours — is handed to the wrapped
 * loader and returned exactly as it came back. Without that second narrowing
 * the namespace would be a passthrough to the application's whole `lang/`
 * directory, and `panda-panel::greetings.hello` would answer with somebody's
 * unrelated `lang/en/greetings.php`.
 */
final class PanelTranslationLoader implements Loader
{
    /**
     * The group names the package ships, in any locale.
     *
     * Read from disk once per loader rather than written down, so a group
     * added in a later release needs no second edit here — and cached,
     * because this is asked on every group the translator loads.
     *
     * @var list<string>|null
     */
    private ?array $groups = null;

    /**
     * @param  Closure(): string  $langPath  the application's `lang/`, resolved per call
     *                                       so a test that moves it is followed
     * @param  string  $packageLang  the package's own `lang/`, which defines
     *                               which groups this namespace owns
     */
    public function __construct(
        private readonly Loader $loader,
        private readonly Filesystem $files,
        private readonly Closure $langPath,
        private readonly string $namespace,
        private readonly string $packageLang,
    ) {}

    /**
     * @param  string  $locale
     * @param  string  $group
     * @param  string|null  $namespace
     * @return array<mixed>
     */
    public function load($locale, $group, $namespace = null): array
    {
        $lines = $this->loader->load($locale, $group, $namespace);

        if ($namespace !== $this->namespace || ! $this->ships($group)) {
            return $lines;
        }

        $file = ($this->langPath)()."/{$locale}/{$group}.php";

        if (! $this->files->exists($file)) {
            return $lines;
        }

        $override = $this->files->getRequire($file);

        return is_array($override) ? array_replace_recursive($lines, $override) : $lines;
    }

    /**
     * @param  string  $namespace
     * @param  string  $hint
     */
    public function addNamespace($namespace, $hint): void
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    /**
     * @param  string  $path
     */
    public function addJsonPath($path): void
    {
        $this->loader->addJsonPath($path);
    }

    /**
     * @return array<string, string>
     */
    public function namespaces(): array
    {
        return $this->loader->namespaces();
    }

    /**
     * Whether a group is one of the package's own.
     *
     * The gate on the whole merge. `lang/en/tables.php` is ours to read from
     * because the package ships a `tables` group; `lang/en/greetings.php` is
     * not, however the key was spelled at the call site.
     *
     * Any locale counts, so an application that added `fr/tables.php` for a
     * locale the package does not ship still has it read.
     */
    private function ships(string $group): bool
    {
        $this->groups ??= array_values(array_unique(array_map(
            static fn (string $file): string => basename($file, '.php'),
            glob($this->packageLang.'/*/*.php') ?: [],
        )));

        return in_array($group, $this->groups, true);
    }

    /**
     * Anything the `Loader` contract does not name.
     *
     * `FileLoader::addPath()` is the one that matters — it is how an
     * application adds a second translation directory, and how this
     * repository's own tests point at their fixtures — but the contract is
     * four methods and the class behind it has more. A decorator that
     * implemented only the interface would be a quiet downgrade of whatever
     * loader it wrapped, which is the kind of breakage that surfaces as
     * somebody's translations having silently stopped loading.
     *
     * @param  list<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->loader->{$method}(...$arguments);
    }
}
