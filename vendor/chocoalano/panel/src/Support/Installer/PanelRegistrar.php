<?php

declare(strict_types=1);

namespace PandaPanel\Support\Installer;

use Illuminate\Support\Facades\File;

/**
 * Adds a panel provider to the `panels` list in `config/panda-panel.php`.
 *
 * Panels are listed rather than discovered, and that stays true: this writes
 * the same line a person would, into the same file, in the same place. What
 * it removes is the step where an install finishes, the panel exists, and the
 * first URL 404s because nobody read the last line of the output.
 *
 * The edit is textual rather than a config rewrite for one reason: the file
 * is mostly comments explaining every key, and re-emitting it from the parsed
 * array would throw all of them away. So the rule is that the file must look
 * the way this package shipped it, and anything else is left alone and
 * reported — a config somebody has restructured is a config they are managing
 * themselves.
 *
 * Three outcomes rather than two, because the file ships with the default
 * panel's line already present *and commented out*. Treating that as "already
 * registered" would be the exact failure this class exists to prevent: an
 * install that reports success and leaves the panel unreachable. A commented
 * line is uncommented; a live one is left alone.
 */
final class PanelRegistrar
{
    public const REGISTERED = 'registered';

    public const ALREADY_PRESENT = 'already-present';

    public const NO_CONFIG = 'no-config';

    public const UNRECOGNISED = 'unrecognised';

    /**
     * The `panels` array as this package ships it: the key, its opening
     * bracket, the body, and a closing `],` whose indentation tells us how
     * deep to write.
     */
    private const PANELS_BLOCK = "/('panels'\s*=>\s*\[)(.*?)(\n([ \t]*)\],)/s";

    /**
     * @param  class-string  $provider
     * @param  string|null  $path  the config file, defaulting to the
     *                             application's published one
     * @return self::*
     */
    public static function register(string $provider, ?string $path = null): string
    {
        $path ??= config_path('panda-panel.php');

        if (! File::exists($path)) {
            return self::NO_CONFIG;
        }

        $contents = File::get($path);

        if (preg_match(self::PANELS_BLOCK, $contents, $block) !== 1) {
            return self::UNRECOGNISED;
        }

        $entry = '\\'.ltrim($provider, '\\').'::class';
        $needle = ltrim($provider, '\\').'::class';

        [$body, $result] = self::apply($block[2], $needle, $block[4].'    ', $entry);

        if ($result === self::ALREADY_PRESENT) {
            return $result;
        }

        File::put($path, str_replace($block[0], $block[1].$body.$block[3], $contents));

        return $result;
    }

    /**
     * The body of the `panels` array, with the provider in it.
     *
     * @return array{string, self::REGISTERED|self::ALREADY_PRESENT}
     */
    private static function apply(string $body, string $needle, string $indent, string $entry): array
    {
        $lines = explode("\n", $body);

        foreach ($lines as $index => $line) {
            if (! str_contains($line, $needle)) {
                continue;
            }

            $trimmed = ltrim($line);

            // A live entry: nothing to do, and nothing to write.
            if (! str_starts_with($trimmed, '//') && ! str_starts_with($trimmed, '#')) {
                return [$body, self::ALREADY_PRESENT];
            }

            // The placeholder this package ships. Uncommenting it is what a
            // person would do, and it keeps the line where they put it.
            $lines[$index] = $indent.$entry.',';

            return [implode("\n", $lines), self::REGISTERED];
        }

        // Appended rather than prepended: order decides which panel a user
        // lands in when the request does not name one, so a new panel goes
        // last, where somebody adding one by hand would put it.
        return [rtrim($body, "\n")."\n".$indent.$entry.',', self::REGISTERED];
    }
}
