<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Shared machinery for the panel generators.
 *
 * Stubs rather than string concatenation: a generated class is code someone
 * will read and edit, and building it by interpolation makes both the
 * template and the escaping hard to follow.
 *
 * Nothing is overwritten without `--force`, and every generator reports
 * exactly which files it created and which it left alone.
 */
abstract class PanelGeneratorCommand extends Command
{
    /** @var list<string> */
    private array $created = [];

    /** @var list<string> */
    private array $skipped = [];

    /**
     * The application's published stub when it has one, the package's own
     * otherwise.
     *
     * Publishing a stub is how a project changes what its generators write,
     * so the application's copy always wins. Falling back to the package's
     * means the generators work the moment it is installed, rather than only
     * after someone remembers to publish.
     */
    protected function stubPath(string $name): string
    {
        $published = base_path("stubs/panel/{$name}.stub");

        if (File::exists($published)) {
            return $published;
        }

        return dirname(__DIR__, 3)."/stubs/panel/{$name}.stub";
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function writeStub(string $stub, string $target, array $replacements): bool
    {
        if (File::exists($target) && ! $this->option('force')) {
            $this->skipped[] = $this->relative($target);

            return false;
        }

        $contents = File::get($this->stubPath($stub));

        foreach ($replacements as $search => $replace) {
            $contents = str_replace('{{ '.$search.' }}', $replace, $contents);
        }

        File::ensureDirectoryExists(dirname($target));
        File::put($target, $contents);

        $this->created[] = $this->relative($target);

        return true;
    }

    protected function report(): int
    {
        foreach ($this->created as $path) {
            $this->components->info("Created [{$path}]");
        }

        foreach ($this->skipped as $path) {
            $this->components->warn("Skipped [{$path}] because it already exists. Use --force to overwrite.");
        }

        return $this->created === [] && $this->skipped !== [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * `Admin` and `admin` both mean the same panel, so the studly form is the
     * canonical one for namespaces and paths.
     */
    protected function panelName(string $value): string
    {
        return Str::studly($value);
    }

    private function relative(string $path): string
    {
        return Str::after($path, base_path().DIRECTORY_SEPARATOR);
    }
}
