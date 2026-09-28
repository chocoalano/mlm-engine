<?php

declare(strict_types=1);

namespace PandaPanel\Support;

/**
 * Where the panel's frontend lives inside the application.
 *
 * Two paths, both under `resources/`: the panel's own components, and the
 * tree the generators scaffold into. They are configurable because a project
 * may already have `resources/js` arranged its own way — and they are read
 * through one class because a path that could be spelled differently in the
 * publisher, the generator and the icon command is a path that will be.
 */
final class FrontendPaths
{
    /**
     * The panel's own components, `resources/js/panel` by default.
     */
    public static function panel(string $path = ''): string
    {
        return self::resolve('panel_path', 'js/panel', $path);
    }

    /**
     * Where a generated page or widget component is written,
     * `resources/js/pages/Panels` by default.
     *
     * Also the root of the `import.meta.glob` the frontend resolves component
     * names through, so moving it means changing that glob too.
     */
    public static function pages(string $path = ''): string
    {
        return self::resolve('pages_path', 'js/pages/Panels', $path);
    }

    private static function resolve(string $key, string $default, string $path): string
    {
        $configured = config("panda-panel.frontend.{$key}");

        $base = is_string($configured) && $configured !== '' ? $configured : $default;

        return resource_path(trim($base, '/').($path === '' ? '' : '/'.ltrim($path, '/')));
    }
}
