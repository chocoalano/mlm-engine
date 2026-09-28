<?php

declare(strict_types=1);

namespace PandaPanel\Support\Installer;

use Closure;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Running npm for the application, when the application asked.
 *
 * The installer used to print `npm install …` and stop. That is the right
 * default for a package — a dependency install is the project's decision, and
 * `node_modules` is somebody's disk — but it is not a reason to make it
 * impossible, and the step after "publish the components" is always the same
 * one. So it is offered, once, with the exact command shown before it runs.
 *
 * Everything here is best-effort and says so. A missing binary, a registry
 * that is unreachable, a lockfile conflict: each is reported as a step still
 * outstanding, with the command to run by hand, rather than failing the
 * install. An installer whose last act is to abort over a network timeout has
 * thrown away the six things it already did.
 */
final class PackageManager
{
    /**
     * How long a dependency install is given before it is called stuck.
     *
     * Generous: a cold `npm install` on a slow connection is minutes, and a
     * timeout that fires on a working install is worse than no timeout.
     */
    private const TIMEOUT = 600.0;

    public static function hasNpm(): bool
    {
        return (new ExecutableFinder)->find('npm') !== null;
    }

    public static function hasNodeModules(): bool
    {
        return File::isDirectory(base_path('node_modules'));
    }

    /**
     * `npm install <packages>`, streamed as it goes.
     *
     * @param  list<string>  $packages  `name@range` pairs
     * @param  Closure(string): void  $output
     */
    public static function install(array $packages, Closure $output): bool
    {
        if ($packages === []) {
            return true;
        }

        return self::run(['npm', 'install', ...$packages], $output);
    }

    /**
     * `npm run build`, for the one thing that has to happen after a publish
     * before any of it is on a screen.
     *
     * @param  Closure(string): void  $output
     */
    public static function build(Closure $output): bool
    {
        return self::run(['npm', 'run', 'build'], $output);
    }

    /**
     * @param  list<string>  $command
     * @param  Closure(string): void  $output
     */
    private static function run(array $command, Closure $output): bool
    {
        if (! self::hasNpm()) {
            return false;
        }

        $process = new Process($command, base_path(), timeout: self::TIMEOUT);

        try {
            $process->run(static function (string $type, string $buffer) use ($output): void {
                $output($buffer);
            });
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful();
    }
}
