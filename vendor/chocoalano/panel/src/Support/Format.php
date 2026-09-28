<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Numbers and dates, written the way the current locale writes them.
 *
 * `number_format($value, 2, '.', ',')` is English, and it was English in
 * every panel however the locale was set — so a translated panel showed
 * `1,234.56` to a reader for whom that means one and a bit. Grouping is the
 * one place a half-translated interface is not merely awkward: it is a number
 * misread without anybody noticing.
 *
 * The separators come from `lang/{locale}/formats.php`, which means an
 * application can correct one with the translations publish tag, and a locale
 * somebody adds brings its own.
 *
 * Deliberately not `Illuminate\Support\Number`. That would do this properly
 * through ICU, and it calls `ensureIntlExtensionIsInstalled()` — this package
 * requires only `ext-json` and `ext-zip`, and making `ext-intl` a hard
 * requirement of an admin panel is a real install barrier on shared hosting.
 */
final class Format
{
    /**
     * A grouped number, with the current locale's separators.
     */
    public static function number(float|int $value, int $decimals = 0): string
    {
        return number_format(
            (float) $value,
            $decimals,
            self::separator('decimal_separator', '.'),
            self::separator('thousands_separator', ','),
        );
    }

    /**
     * The same, with trailing zeros after the decimal point removed.
     *
     * A summary row reads `1.5` rather than `1.50`, and `12` rather than
     * `12.00` — but only ever by trimming the decimal part, which is why the
     * separator is matched rather than a literal dot: trimming `1.234,00`
     * with `'.'` would eat the thousands separator and turn it into `1`.
     */
    public static function trimmedNumber(float $value, int $decimals = 2): string
    {
        $decimal = self::separator('decimal_separator', '.');
        $formatted = self::number($value, $decimals);

        if (! str_contains($formatted, $decimal)) {
            return $formatted;
        }

        return rtrim(rtrim($formatted, '0'), $decimal);
    }

    /**
     * The default `date()` format for a date, when nothing said otherwise.
     */
    public static function date(): string
    {
        return self::pattern('date', 'M j, Y');
    }

    /**
     * The default for a date and a time in a table cell.
     */
    public static function dateTime(): string
    {
        return self::pattern('date_time', 'M j, Y H:i');
    }

    /**
     * The same where there is a whole row for it rather than a cell.
     */
    public static function dateTimeVerbose(): string
    {
        return self::pattern('date_time_verbose', 'M j, Y g:ia');
    }

    /**
     * The default where a date has one line of a button to fit in.
     */
    public static function dateCompact(): string
    {
        return self::pattern('date_compact', 'j M Y');
    }

    /**
     * A separator, or the English one if the locale's file says nothing.
     *
     * The fallback matters: a locale added by an application may hold only
     * the sentences, and a missing separator must not become the empty string
     * — `1234.56` grouped with nothing is still readable, `1234 56` is not.
     */
    private static function separator(string $key, string $fallback): string
    {
        $value = Lang::get("panda-panel::formats.{$key}");

        return is_string($value) && $value !== "panda-panel::formats.{$key}"
            ? $value
            : $fallback;
    }

    private static function pattern(string $key, string $fallback): string
    {
        $value = Lang::get("panda-panel::formats.{$key}");

        return is_string($value) && $value !== '' && $value !== "panda-panel::formats.{$key}"
            ? $value
            : $fallback;
    }
}
