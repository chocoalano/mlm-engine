<?php

declare(strict_types=1);

namespace PandaPanel\Support\Installer;

use Illuminate\Support\Facades\File;

/**
 * Adds Inertia's middleware to the `web` group in `bootstrap/app.php`.
 *
 * `php artisan inertia:middleware` writes the class and stops there, which is
 * correct — it does not know which group anybody wants it in — and leaves the
 * one step that makes it do anything. On a starter kit the line is already
 * there; on a blank application it is not, and the symptom is a panel that
 * renders with no shared props and no asset versioning, on an HTTP 200.
 *
 * The same rules as `PanelRegistrar`, for the same reasons: a textual edit
 * into the file a person would have edited, only where the file still looks
 * the way Laravel shipped it, and a report rather than a guess when it does
 * not. `bootstrap/app.php` is the most rewritten file in a Laravel
 * application, and an installer that pattern-matched its way into somebody's
 * restructured middleware stack would be gambling with the one file that
 * decides whether the application boots.
 */
final class InertiaMiddlewareRegistrar
{
    public const REGISTERED = 'registered';

    public const ALREADY_PRESENT = 'already-present';

    public const NO_BOOTSTRAP = 'no-bootstrap';

    public const NO_MIDDLEWARE = 'no-middleware';

    public const UNRECOGNISED = 'unrecognised';

    /**
     * The `withMiddleware()` closure: its opening, its body, and a closing
     * `})` whose indentation says how deep to write.
     */
    private const MIDDLEWARE_BLOCK = '/(->withMiddleware\(function \(Middleware \$middleware\)(?:\s*:\s*void)?\s*\{)(.*?)(\n([ \t]*)\}\))/s';

    /**
     * The class `inertia:middleware` writes, under this application's own
     * namespace rather than a hardcoded `App\`.
     *
     * The same call Inertia's own generator makes, so the two cannot disagree
     * about where the file went. It throws for an application whose
     * `composer.json` does not map its `app/` directory — which no real
     * application is, and which this package's own test harness is, because
     * the suite runs the package itself as the application. `App\` is the
     * right answer in every case where the question is being asked for real.
     */
    public static function middleware(): string
    {
        try {
            $root = app()->getNamespace();
        } catch (\Throwable) {
            $root = 'App\\';
        }

        return $root.'Http\\Middleware\\HandleInertiaRequests';
    }

    public static function isPublished(): bool
    {
        return File::exists(app_path('Http/Middleware/HandleInertiaRequests.php'));
    }

    /**
     * @param  string|null  $path  the bootstrap file, defaulting to this
     *                             application's
     * @return self::*
     */
    public static function register(?string $path = null): string
    {
        $path ??= base_path('bootstrap/app.php');

        if (! self::isPublished()) {
            return self::NO_MIDDLEWARE;
        }

        if (! File::exists($path)) {
            return self::NO_BOOTSTRAP;
        }

        $contents = File::get($path);
        $class = self::middleware();

        if (str_contains($contents, 'HandleInertiaRequests')) {
            return self::ALREADY_PRESENT;
        }

        if (preg_match(self::MIDDLEWARE_BLOCK, $contents, $block) !== 1) {
            return self::UNRECOGNISED;
        }

        $indent = $block[4].'    ';

        $appended = sprintf(
            "\n%s\$middleware->web(append: [\n%s    \\%s::class,\n%s]);\n",
            $indent,
            $indent,
            $class,
            $indent,
        );

        File::put($path, str_replace(
            $block[0],
            $block[1].self::body($block[2]).$appended.$block[3],
            $contents,
        ));

        return self::REGISTERED;
    }

    /**
     * The closure body, with Laravel's empty placeholder removed.
     *
     * A `//` on its own is what `laravel new` leaves behind to say "your
     * middleware goes here". Keeping it above a real call reads as a comment
     * about that call.
     */
    private static function body(string $body): string
    {
        if (trim($body) === '//') {
            return '';
        }

        return rtrim($body, "\n");
    }
}
