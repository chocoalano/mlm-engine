<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use JsonException;

/**
 * @internal
 *
 * A commission's trace as the package keeps it: inert JSON data — strings,
 * integers, booleans, null, lists and objects with text keys — with every
 * object's keys in a canonical order, so the same trace is always stored,
 * and read back, the same way, whatever order a database keeps JSON keys in.
 *
 * No floats: a number in an audit trail is written as its exact canonical
 * string. Bounded against pathological values: at most 16 levels deep,
 * 10,000 values and 64 KiB of JSON.
 */
final class CommissionTrace
{
    public const MAX_DEPTH = 16;

    public const MAX_NODES = 10_000;

    public const MAX_BYTES = 65_536;

    private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Why `$trace` is not a trace, or null if it is one.
     *
     * @param  array<array-key, mixed>  $trace
     */
    public static function problem(array $trace): ?string
    {
        $nodes = 0;
        $problem = self::valueProblem($trace, 'trace', 1, $nodes);

        if ($problem !== null) {
            return $problem;
        }

        if ($nodes > self::MAX_NODES) {
            return 'it holds more than '.self::MAX_NODES.' values.';
        }

        if (strlen(self::encode($trace)) > self::MAX_BYTES) {
            return 'it is larger than '.self::MAX_BYTES.' bytes of JSON.';
        }

        return null;
    }

    /**
     * `$trace` with every object's keys sorted, lists left in order.
     *
     * @param  array<array-key, mixed>  $trace
     * @return array<array-key, mixed>
     */
    public static function canonical(array $trace): array
    {
        if (! array_is_list($trace)) {
            ksort($trace, SORT_STRING);
        }

        return array_map(static fn (mixed $value): mixed => is_array($value) ? self::canonical($value) : $value, $trace);
    }

    /**
     * @param  array<array-key, mixed>  $trace
     */
    public static function encode(array $trace): string
    {
        return json_encode(self::canonical($trace), self::JSON);
    }

    /**
     * A stored trace, read back canonically.
     *
     * @return array<array-key, mixed>
     *
     * @throws JsonException
     */
    public static function decode(string $json): array
    {
        $trace = json_decode($json, true, self::MAX_DEPTH + 2, JSON_THROW_ON_ERROR);

        return is_array($trace) ? self::canonical($trace) : [];
    }

    private static function valueProblem(mixed $value, string $at, int $depth, int &$nodes): ?string
    {
        $nodes++;

        if ($depth > self::MAX_DEPTH) {
            return "{$at} nests deeper than ".self::MAX_DEPTH.' levels.';
        }

        if (is_array($value)) {
            $list = array_is_list($value);

            foreach ($value as $key => $item) {
                if (! $list && ! is_string($key)) {
                    return "{$at} mixes numbered and named entries; a JSON object has text keys only.";
                }

                if (is_string($key) && ! mb_check_encoding($key, 'UTF-8')) {
                    return "{$at} has a key that is not valid UTF-8.";
                }

                $problem = self::valueProblem($item, "{$at}.{$key}", $depth + 1, $nodes);

                if ($problem !== null) {
                    return $problem;
                }
            }

            return null;
        }

        return match (true) {
            is_string($value) => mb_check_encoding($value, 'UTF-8') ? null : "{$at} is not valid UTF-8 text.",
            is_int($value), is_bool($value), $value === null => null,
            is_float($value) => "{$at} is a float; write numbers in a trace as exact strings.",
            default => "{$at} is ".get_debug_type($value).', which is not JSON data.',
        };
    }
}
