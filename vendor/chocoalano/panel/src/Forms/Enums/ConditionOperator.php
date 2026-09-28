<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Enums;

/**
 * How one field's value is compared against another's when deciding whether
 * a field shows.
 *
 * A closed set, and the whole reason this exists rather than a `hiddenJs()`
 * that ships JavaScript from the server. A condition has to be evaluated in
 * the browser to react as somebody types, but nothing executable may cross
 * the wire — so the server sends a *description* of the comparison and the
 * frontend interprets it with code that was compiled in.
 *
 * The cost is that a condition must be expressible here. That is the point:
 * anything that is not is a server-side `visible()` closure instead, which
 * is evaluated once and cannot react. The two are honest about which is
 * which.
 */
enum ConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case In = 'in';
    case NotIn = 'not_in';
    case Filled = 'filled';
    case Blank = 'blank';
    case GreaterThan = 'greater_than';
    case LessThan = 'less_than';
    case Truthy = 'truthy';
    case Falsy = 'falsy';

    /**
     * Whether the comparison needs something to compare against.
     */
    public function needsValue(): bool
    {
        return ! in_array(
            $this,
            [self::Filled, self::Blank, self::Truthy, self::Falsy],
            true,
        );
    }

    /**
     * Evaluates the condition on the server, for the cases where the schema
     * is being resolved without a browser — the initial render, and the
     * validation pass that must agree with it.
     */
    public function matches(mixed $state, mixed $expected): bool
    {
        return match ($this) {
            self::Equals => self::scalar($state) === self::scalar($expected),
            self::NotEquals => self::scalar($state) !== self::scalar($expected),
            self::In => is_array($expected) && in_array(self::scalar($state), array_map(self::scalar(...), $expected), true),
            self::NotIn => ! is_array($expected) || ! in_array(self::scalar($state), array_map(self::scalar(...), $expected), true),
            self::Filled => $state !== null && $state !== '' && $state !== [],
            self::Blank => $state === null || $state === '' || $state === [],
            self::GreaterThan => is_numeric($state) && is_numeric($expected) && (float) $state > (float) $expected,
            self::LessThan => is_numeric($state) && is_numeric($expected) && (float) $state < (float) $expected,
            self::Truthy => (bool) $state,
            self::Falsy => ! $state,
        };
    }

    /**
     * Compared as strings, so `'1'` from a form and `1` from a model are the
     * same answer. A form's values arrive as text whatever the column holds.
     */
    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
