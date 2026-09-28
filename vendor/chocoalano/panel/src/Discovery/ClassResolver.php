<?php

declare(strict_types=1);

namespace PandaPanel\Discovery;

use Composer\Autoload\ClassLoader;

/**
 * Turns a file path into the class it declares.
 *
 * Uses Composer's registered PSR-4 prefixes rather than parsing or
 * evaluating the file. Reading a file to find out what class it declares
 * would mean executing or tokenizing arbitrary source during discovery; the
 * autoloader already knows the answer.
 */
final class ClassResolver
{
    /** @var array<string, list<string>>|null */
    private static ?array $prefixes = null;

    /**
     * Null when the path is outside every registered PSR-4 root, which means
     * nothing could autoload it anyway.
     *
     * @return class-string|null
     */
    public static function forPath(string $path): ?string
    {
        $path = self::normalize($path);

        foreach (self::prefixes() as $namespace => $roots) {
            foreach ($roots as $root) {
                $root = self::normalize($root);

                if ($root === '' || ! str_starts_with($path, $root.'/')) {
                    continue;
                }

                $relative = substr($path, strlen($root) + 1);

                if (! str_ends_with($relative, '.php')) {
                    continue;
                }

                $class = $namespace.str_replace('/', '\\', substr($relative, 0, -4));

                /** @var class-string $class */
                return $class;
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function prefixes(): array
    {
        if (self::$prefixes !== null) {
            return self::$prefixes;
        }

        $prefixes = [];

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getPrefixesPsr4() as $namespace => $roots) {
                $prefixes[$namespace] = array_map(strval(...), $roots);
            }
        }

        // Longest namespace first, so a nested prefix wins over its parent.
        uksort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return self::$prefixes = $prefixes;
    }

    private static function normalize(string $path): string
    {
        $real = realpath($path);

        return rtrim(str_replace('\\', '/', $real === false ? $path : $real), '/');
    }
}
