<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Finance;

/**
 * How an exact amount finer than one financial millionth becomes a
 * six-decimal amount (ADR-020). Always chosen explicitly — by a plan
 * definition — and never by default, by currency or by configuration.
 *
 * Each mode decides from the whole millionths and the sub-millionth
 * remainder of a non-negative amount, in millionths of a millionth:
 *
 * - `toward_zero`: keep the whole millionths; drop the remainder;
 * - `away_from_zero`: add one millionth whenever anything remains;
 * - `half_up`: nearest millionth; an exact half goes up;
 * - `half_even`: nearest millionth; an exact half goes to the even one.
 */
enum FinancialRoundingMode: string
{
    case TowardZero = 'toward_zero';
    case AwayFromZero = 'away_from_zero';
    case HalfUp = 'half_up';
    case HalfEven = 'half_even';

    /**
     * The mode spelled exactly so, or null: nothing is trimmed, lowercased
     * or aliased.
     */
    public static function parse(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * Whether a non-negative amount of `$remainder` millionths of a
     * millionth (0 to 999,999) beyond whole millionths ending in an odd or
     * even digit rounds to one more millionth.
     */
    public function increments(int $remainder, bool $odd): bool
    {
        return match ($this) {
            self::TowardZero => false,
            self::AwayFromZero => $remainder > 0,
            self::HalfUp => $remainder >= 500_000,
            self::HalfEven => $remainder > 500_000 || ($remainder === 500_000 && $odd),
        };
    }
}
