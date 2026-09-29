<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies\Support;

use PandaBear\Mlm\Exceptions\InvalidFinancialAmount;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Models\LedgerPosting;

/**
 * @internal
 *
 * The parameter rules the package's fixed-award strategies share: an exact
 * set of fields, and fixed financial awards. Refuses rather than rewrites.
 */
final class StrategyParameters
{
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

    public static function invalid(string $strategy, string $reason): InvalidPlanDefinition
    {
        return InvalidPlanDefinition::input("{$strategy} parameters", $reason);
    }
}
