<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies\Support;

use Closure;
use PandaBear\Mlm\Exceptions\InvalidFinancialAmount;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Finance\FinancialRoundingMode;
use PandaBear\Mlm\Models\LedgerPosting;

/**
 * @internal
 *
 * The parameter rules the package's strategies share: an exact set of
 * fields, fixed awards, amounts per unit, rounding modes and levels by
 * depth. Refuses rather than rewrites.
 */
final class StrategyParameters
{
    /**
     * The deepest sponsor depth a level may name.
     */
    public const MAX_DEPTH = 100;

    /**
     * @param  array<array-key, mixed>  $parameters
     * @param  list<string>  $fields
     *
     * @throws InvalidPlanDefinition
     */
    public static function exactly(string $strategy, array $parameters, array $fields, string $what = 'parameters'): void
    {
        $given = array_map('strval', array_keys($parameters));
        $missing = array_values(array_diff($fields, $given));
        $unknown = array_values(array_diff($given, $fields));

        if ($missing !== [] || $unknown !== []) {
            throw self::invalid($strategy, sprintf(
                '%s are exactly %s%s%s.',
                $what,
                implode(', ', $fields),
                $missing === [] ? '' : '; missing: '.implode(', ', $missing),
                $unknown === [] ? '' : '; unknown: '.implode(', ', $unknown),
            ));
        }
    }

    /**
     * A fixed award in the component's currency: an exact, strictly positive
     * amount that fits one ledger posting — a decimal string or an integer,
     * never a float.
     *
     * @throws InvalidPlanDefinition
     */
    public static function award(string $strategy, string $field, mixed $value): FinancialAmount
    {
        try {
            $amount = FinancialAmount::of($value);
        } catch (InvalidFinancialAmount $exception) {
            throw self::invalid($strategy, "\"{$field}\": {$exception->getMessage()}");
        }

        if (! $amount->isPositive()) {
            throw self::invalid($strategy, "\"{$field}\" is a strictly positive award; {$amount} given.");
        }

        $limit = FinancialAmount::fromMillionths(LedgerPosting::MAX_MILLIONTHS);

        if ($amount->compare($limit) > 0) {
            throw self::invalid($strategy, "\"{$field}\" is {$amount}, more than one ledger posting holds ({$limit}).");
        }

        return $amount;
    }

    /**
     * An amount of money per unit of something — not a final award, so not
     * bound by what one posting holds: exact, strictly positive, a decimal
     * string or an integer, never a float.
     *
     * @throws InvalidPlanDefinition
     */
    public static function positiveAmount(string $strategy, string $field, mixed $value): FinancialAmount
    {
        try {
            $amount = FinancialAmount::of($value);
        } catch (InvalidFinancialAmount $exception) {
            throw self::invalid($strategy, "\"{$field}\": {$exception->getMessage()}");
        }

        if (! $amount->isPositive()) {
            throw self::invalid($strategy, "\"{$field}\" is strictly positive; {$amount} given.");
        }

        return $amount;
    }

    /**
     * The rounding mode, spelled exactly as one of the four: there is no
     * default.
     *
     * @throws InvalidPlanDefinition
     */
    public static function rounding(string $strategy, mixed $value): FinancialRoundingMode
    {
        return FinancialRoundingMode::parse($value) ?? throw self::invalid($strategy, sprintf(
            '"rounding" is one of %s, spelled exactly; %s given.',
            implode(', ', array_map(static fn (FinancialRoundingMode $mode): string => $mode->value, FinancialRoundingMode::cases())),
            is_string($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : get_debug_type($value),
        ));
    }

    /**
     * Levels by sponsor depth: a non-empty list of objects of exactly an
     * integer `depth` from 1 to 100 — each once, in any order — and an amount
     * field read by `$amount`.
     *
     * @param  Closure(string, mixed): FinancialAmount  $amount  given the field path and its value
     * @return array<int, FinancialAmount> by depth, shallowest first
     *
     * @throws InvalidPlanDefinition
     */
    public static function depthLevels(string $strategy, mixed $listed, string $amountField, Closure $amount): array
    {
        if (! is_array($listed) || $listed === [] || ! array_is_list($listed)) {
            throw self::invalid($strategy, "\"levels\" is a non-empty list of {depth, {$amountField}}.");
        }

        $fields = ['depth', $amountField];
        sort($fields);
        $levels = [];

        foreach ($listed as $index => $level) {
            if (! is_array($level) || ($level !== [] && array_is_list($level))) {
                throw self::invalid($strategy, "\"levels\"[{$index}] is an object of depth and {$amountField}.");
            }

            self::exactly($strategy, $level, $fields, "\"levels\"[{$index}] fields");
            $depth = $level['depth'];

            if (! is_int($depth) || $depth < 1 || $depth > self::MAX_DEPTH) {
                throw self::invalid($strategy, sprintf('"levels"[%d].depth is a whole number from 1 to %d; %s given.', $index, self::MAX_DEPTH, is_int($depth) ? $depth : get_debug_type($depth)));
            }

            if (isset($levels[$depth])) {
                throw self::invalid($strategy, "\"levels\" names depth {$depth} more than once.");
            }

            $levels[$depth] = $amount("levels[{$index}].{$amountField}", $level[$amountField]);
        }

        ksort($levels);

        return $levels;
    }

    public static function invalid(string $strategy, string $reason): InvalidPlanDefinition
    {
        return InvalidPlanDefinition::input("{$strategy} parameters", $reason);
    }
}
