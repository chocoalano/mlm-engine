<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning;

use JsonException;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;

/**
 * @internal
 *
 * The input rules a plan definition shares: machine keys, names, positions,
 * and parameters. Refuses rather than rewrites — nothing is lowercased,
 * trimmed or cast on the caller's behalf.
 *
 * Parameters are inert data for trusted code to read: a JSON object whose
 * values are strings, numbers, booleans, null, lists or further objects.
 * Nothing in them is ever run, and no PHP object — closure, model, enum —
 * is accepted in place of data, rather than being quietly converted.
 */
final class DefinitionInput
{
    /**
     * A component or rule key: 1–64 characters, the column's size.
     */
    public const KEY_LENGTH = 64;

    /**
     * A driver or metric key: 1–100 characters, as metric keys are.
     */
    public const DRIVER_LENGTH = 100;

    public const NAME_LENGTH = 255;

    /**
     * How deeply parameter values may nest: a guard against a pathological
     * value, not a business limit.
     */
    public const PARAMETER_DEPTH = 32;

    private const IDENTIFIER = '/^[a-z0-9][a-z0-9._-]*$/D';

    private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Lowercase letters, digits, ".", "-" and "_", starting with a letter or
     * digit, at most `$length` characters.
     */
    public static function isIdentifier(mixed $value, int $length): bool
    {
        return is_string($value) && strlen($value) <= $length && preg_match(self::IDENTIFIER, $value) === 1;
    }

    public static function key(string $field, mixed $value): string
    {
        if (! self::isIdentifier($value, self::KEY_LENGTH)) {
            throw InvalidPlanDefinition::input($field, sprintf(
                '%s is not a key; a key is 1–%d lowercase letters, digits, ".", "-" or "_", starting with a letter or digit.',
                self::describe($value),
                self::KEY_LENGTH,
            ));
        }

        return $value;
    }

    public static function driver(mixed $value): string
    {
        if (! self::isIdentifier($value, self::DRIVER_LENGTH)) {
            throw InvalidPlanDefinition::input('component driver', sprintf(
                '%s is not a driver key; a driver key is 1–%d lowercase letters, digits, ".", "-" or "_", starting with a letter or digit.',
                self::describe($value),
                self::DRIVER_LENGTH,
            ));
        }

        return $value;
    }

    /**
     * A human-readable name: not empty, no surrounding whitespace, no control
     * characters, at most 255 characters. Not unique, not a key.
     */
    public static function name(string $field, mixed $value): string
    {
        if (! is_string($value)
            || $value === ''
            || trim($value) !== $value
            || ! mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value) > self::NAME_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw InvalidPlanDefinition::input($field, sprintf(
                'a name is 1–%d characters of text, without surrounding whitespace or control characters.',
                self::NAME_LENGTH,
            ));
        }

        return $value;
    }

    public static function position(string $field, mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > 4_294_967_295) {
            throw InvalidPlanDefinition::input($field, 'a position is a whole number from 0 to 4294967295.');
        }

        return $value;
    }

    /**
     * Parameters as the definition keeps them: a JSON object of inert values,
     * its keys in a canonical order at every level.
     *
     * @return array<string, mixed>
     */
    public static function parameters(string $field, mixed $value): array
    {
        $problem = self::parametersProblem($value);

        if ($problem !== null) {
            throw InvalidPlanDefinition::input($field, $problem);
        }

        return self::canonical($value);
    }

    /**
     * Why `$value` is not a parameters object, or null if it is one.
     */
    public static function parametersProblem(mixed $value): ?string
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return 'parameters are a JSON object — an array keyed by name — not '.self::describe($value).'.';
        }

        return self::valueProblem($value, 'parameters', 1);
    }

    /**
     * `$value` with every object's keys sorted, lists left in order.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(static fn (mixed $item): mixed => is_array($item) ? self::canonical($item) : $item, $value);
    }

    /**
     * The stored text of a parameters object. An empty one is stored as "{}".
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function json(array $parameters): string
    {
        return $parameters === [] ? '{}' : json_encode($parameters, self::JSON);
    }

    /**
     * Parameters read back from their stored text, held to the same rules
     * they were written under. Text only a raw write could have produced is
     * refused, never read as something else.
     *
     * @return array<string, mixed>
     */
    public static function stored(mixed $json, string $what): array
    {
        try {
            $value = is_string($json) ? json_decode($json, true, 128, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            throw InvalidPlanDefinition::unreadable($what, 'it is not valid JSON.', $exception);
        }

        $problem = self::parametersProblem($value);

        if ($problem !== null) {
            throw InvalidPlanDefinition::unreadable($what, $problem);
        }

        return self::canonical($value);
    }

    private static function valueProblem(mixed $value, string $at, int $depth): ?string
    {
        if ($depth > self::PARAMETER_DEPTH) {
            return "{$at} nests deeper than ".self::PARAMETER_DEPTH.' levels.';
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (! array_is_list($value) && ! is_string($key)) {
                    return "{$at} mixes numbered and named entries; a JSON object has named keys only.";
                }

                $problem = self::valueProblem($item, "{$at}.{$key}", $depth + 1);

                if ($problem !== null) {
                    return $problem;
                }
            }

            return null;
        }

        return match (true) {
            is_string($value) => mb_check_encoding($value, 'UTF-8') ? null : "{$at} is not valid UTF-8 text.",
            is_int($value), is_bool($value), $value === null => null,
            is_float($value) => is_finite($value) ? null : "{$at} is not a finite number.",
            default => "{$at} is ".self::describe($value).', which is not JSON data.',
        };
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"'.mb_strimwidth($value, 0, 40, '…').'"',
            is_array($value) => array_is_list($value) ? 'a list' : 'an array',
            default => get_debug_type($value),
        };
    }
}
