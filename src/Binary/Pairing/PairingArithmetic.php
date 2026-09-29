<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Pairing;

use InvalidArgumentException;
use PandaBear\Mlm\Commission\Strategies\Support\ProportionalAwardCalculator;
use PandaBear\Mlm\Volume\Quantity;

/**
 * @internal
 *
 * Exact arithmetic on non-negative whole numbers written as decimal digits
 * — counts of millionths, and pair counts — at any size: carry totals and
 * pair counts are sums, and may outgrow a 64-bit integer. No float, no
 * extension; multiplication is the proportional calculator's own.
 */
final class PairingArithmetic
{
    /**
     * Digits added or subtracted at a time.
     */
    private const LIMB = 9;

    public static function add(string $a, string $b): string
    {
        [$a, $b] = [self::limbs($a), self::limbs($b)];
        $sum = [];
        $carry = 0;

        for ($i = 0, $n = max(count($a), count($b)); $i < $n; $i++) {
            $cell = ($a[$i] ?? 0) + ($b[$i] ?? 0) + $carry;
            $sum[] = $cell % 10 ** self::LIMB;
            $carry = intdiv($cell, 10 ** self::LIMB);
        }

        if ($carry > 0) {
            $sum[] = $carry;
        }

        return self::digits($sum);
    }

    /**
     * `$a - $b`, which must not be negative.
     */
    public static function subtract(string $a, string $b): string
    {
        if (self::compare($a, $b) < 0) {
            throw new InvalidArgumentException("{$a} − {$b} would be negative.");
        }

        [$a, $b] = [self::limbs($a), self::limbs($b)];
        $difference = [];
        $borrow = 0;

        foreach ($a as $i => $limb) {
            $cell = $limb - ($b[$i] ?? 0) - $borrow;
            $borrow = $cell < 0 ? 1 : 0;
            $difference[] = $cell + $borrow * 10 ** self::LIMB;
        }

        return self::digits($difference);
    }

    /**
     * -1, 0 or 1: by length, then digit by digit.
     */
    public static function compare(string $a, string $b): int
    {
        [$a, $b] = [self::canonical($a), self::canonical($b)];

        return (strlen($a) <=> strlen($b)) ?: (strcmp($a, $b) <=> 0);
    }

    public static function min(string $a, string $b): string
    {
        return self::compare($a, $b) <= 0 ? self::canonical($a) : self::canonical($b);
    }

    /**
     * How many whole times `$divisor` fits in `$dividend`: long division, a
     * digit at a time.
     */
    public static function floorDivide(string $dividend, string $divisor): string
    {
        $divisor = self::canonical($divisor);

        if ($divisor === '0') {
            throw new InvalidArgumentException('A pair quantity of zero divides nothing.');
        }

        $quotient = '';
        $remainder = '0';

        foreach (str_split(self::canonical($dividend)) as $digit) {
            $remainder = self::canonical($remainder.$digit);
            $times = 0;

            while (self::compare($remainder, $divisor) >= 0) {
                $remainder = self::subtract($remainder, $divisor);
                $times++;
            }

            $quotient .= $times;
        }

        return self::canonical($quotient);
    }

    public static function multiply(string $a, string $b): string
    {
        return ProportionalAwardCalculator::multiply(self::canonical($a), self::canonical($b));
    }

    /**
     * A non-negative quantity as its whole number of millionths.
     */
    public static function millionths(Quantity $quantity): string
    {
        if ($quantity->isNegative()) {
            throw new InvalidArgumentException("Binary carry is never negative; {$quantity} given.");
        }

        return $quantity->toMillionths();
    }

    /**
     * Millionths as the quantity's canonical decimal text.
     */
    public static function quantity(string $millionths): string
    {
        return Quantity::fromMillionths(self::canonical($millionths))->value();
    }

    private static function canonical(string $digits): string
    {
        if (preg_match('/^\d+$/D', $digits) !== 1) {
            throw new InvalidArgumentException("\"{$digits}\" is not a non-negative whole number.");
        }

        $digits = ltrim($digits, '0');

        return $digits === '' ? '0' : $digits;
    }

    /**
     * @return list<int> least significant first
     */
    private static function limbs(string $digits): array
    {
        $digits = self::canonical($digits);
        $limbs = [];

        for ($end = strlen($digits); $end > 0; $end -= self::LIMB) {
            $start = max(0, $end - self::LIMB);
            $limbs[] = (int) substr($digits, $start, $end - $start);
        }

        return $limbs;
    }

    /**
     * @param  list<int>  $limbs  least significant first
     */
    private static function digits(array $limbs): string
    {
        $digits = '';

        foreach (array_reverse($limbs) as $limb) {
            $digits .= str_pad((string) $limb, self::LIMB, '0', STR_PAD_LEFT);
        }

        return self::canonical($digits === '' ? '0' : $digits);
    }
}
