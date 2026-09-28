<?php

declare(strict_types=1);

namespace PandaPanel\Widgets\Support;

use PandaPanel\Exceptions\PanelSchemaException;

/**
 * Normalizes a widget's column span into one value per breakpoint.
 *
 * The frontend maps each value to a literal Tailwind class, so a value the
 * map does not cover would compile to nothing. Anything unrecognised is
 * clamped to 1 rather than silently producing an unstyled widget.
 */
final class ColumnSpan
{
    /** @var list<string> */
    private const BREAKPOINTS = ['default', 'md', 'lg', 'xl'];

    private const MAX = 4;

    /**
     * @param  int|string|array<string, int|string>  $span
     * @return array{default: int|string, md: int|string, lg: int|string, xl: int|string}
     */
    public static function normalize(int|string|array $span, string $context = 'A widget'): array
    {
        if (! is_array($span)) {
            $value = self::clamp($span, $context);

            return ['default' => $value, 'md' => $value, 'lg' => $value, 'xl' => $value];
        }

        // A key that is not a breakpoint used to be skipped in silence, so a
        // `'xxl' => 3` — or a `'sm'`, which this grid does not have — was a
        // line of configuration that did nothing.
        $unknown = array_values(array_diff(array_keys($span), self::BREAKPOINTS));

        if ($unknown !== []) {
            throw PanelSchemaException::unknownBreakpoints($context, $unknown, self::BREAKPOINTS);
        }

        $normalized = [];
        $previous = 1;

        foreach (self::BREAKPOINTS as $breakpoint) {
            // A breakpoint that is not declared inherits the one below it,
            // which is how CSS breakpoints behave anyway.
            $previous = array_key_exists($breakpoint, $span)
                ? self::clamp($span[$breakpoint], $context)
                : $previous;

            $normalized[$breakpoint] = $previous;
        }

        /** @var array{default: int|string, md: int|string, lg: int|string, xl: int|string} $normalized */
        return $normalized;
    }

    /**
     * Numbers are clamped; anything else is refused.
     *
     * The difference matters. A span of 99 is somebody asking for more columns
     * than the grid has, and four is the honest answer. A span of `'ful'` is a
     * typo, and answering it with `1` produces a widget a quarter of the width
     * that was asked for, with nothing to say why — which is exactly the
     * failure this whole audit is about.
     */
    private static function clamp(int|string $value, string $context): int|string
    {
        if ($value === 'full') {
            return 'full';
        }

        if (! is_numeric($value)) {
            throw PanelSchemaException::unusableColumnSpan($context, (string) $value);
        }

        return max(1, min((int) $value, self::MAX));
    }
}
