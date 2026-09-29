<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies\Support;

use InvalidArgumentException;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Finance\FinancialRoundingMode;
use PandaBear\Mlm\Volume\Quantity;

/**
 * @internal
 *
 * A proportional award, exactly (ADR-020): a business quantity times a
 * financial amount per one unit of it, rounded to financial millionths by
 * an explicit mode.
 *
 * With Q the quantity in millionths and U the unit amount in millionths,
 * Q × U is the award in millionths of a millionth — exact, twelve decimal
 * places — and the award in millionths is Q × U / 1,000,000, rounded. The
 * product is computed on decimal digits, at any size: no float, no 64-bit
 * limit, no extension. The quantity and the money meet here, never in the
 * finance or volume values themselves.
 */
final class ProportionalAwardCalculator
{
    /**
     * Digits multiplied at a time: two limbs' product and its carries stay
     * far inside a 64-bit integer.
     */
    private const LIMB = 7;

    private const SCALE = 6;

    /**
     * @throws InvalidArgumentException for a negative quantity or unit amount
     */
    public static function calculate(Quantity $quantity, FinancialAmount $unitAmount, FinancialRoundingMode $rounding): ProportionalAwardResult
    {
        if ($quantity->isNegative() || $unitAmount->isNegative()) {
            throw new InvalidArgumentException('A proportional award multiplies a non-negative quantity by a non-negative amount per unit.');
        }

        // Millionths of a millionth: twelve implied decimal places.
        $product = self::multiply($quantity->toMillionths(), $unitAmount->toMillionths());
        $padded = str_pad($product, self::SCALE + 1, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($padded, 0, -self::SCALE), '0');
        $whole = $whole === '' ? '0' : $whole;
        $remainder = (int) substr($padded, -self::SCALE);

        $amount = FinancialAmount::fromMillionths($whole);

        if ($rounding->increments($remainder, ((int) substr($whole, -1)) % 2 === 1)) {
            $amount = $amount->add(FinancialAmount::fromMillionths(1));
        }

        return new ProportionalAwardResult($amount, self::decimal($product), $rounding, $remainder !== 0);
    }

    /**
     * The exact product of two non-negative integers written in decimal
     * digits, schoolbook, a limb at a time.
     */
    public static function multiply(string $a, string $b): string
    {
        foreach ([$a, $b] as $factor) {
            if (preg_match('/^\d+$/D', $factor) !== 1) {
                throw new InvalidArgumentException("\"{$factor}\" is not a non-negative whole number.");
            }
        }

        [$a, $b] = [self::limbs($a), self::limbs($b)];
        $product = array_fill(0, count($a) + count($b), 0);
        $base = 10 ** self::LIMB;

        foreach ($a as $i => $x) {
            $carry = 0;

            foreach ($b as $j => $y) {
                $cell = $product[$i + $j] + $x * $y + $carry;
                $product[$i + $j] = $cell % $base;
                $carry = intdiv($cell, $base);
            }

            for ($k = $i + count($b); $carry > 0; $k++) {
                $cell = $product[$k] + $carry;
                $product[$k] = $cell % $base;
                $carry = intdiv($cell, $base);
            }
        }

        $digits = '';

        foreach (array_reverse($product) as $limb) {
            $digits .= str_pad((string) $limb, self::LIMB, '0', STR_PAD_LEFT);
        }

        $digits = ltrim($digits, '0');

        return $digits === '' ? '0' : $digits;
    }

    /**
     * Millionths of a millionth as canonical decimal text: "1500000" is
     * "0.0000015", "3000000000000" is "3".
     */
    private static function decimal(string $product): string
    {
        $scale = 2 * self::SCALE;
        $padded = str_pad($product, $scale + 1, '0', STR_PAD_LEFT);
        $integer = ltrim(substr($padded, 0, -$scale), '0');
        $fraction = rtrim(substr($padded, -$scale), '0');

        return ($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);
    }

    /**
     * @return list<int> least significant first
     */
    private static function limbs(string $digits): array
    {
        $limbs = [];

        for ($end = strlen($digits); $end > 0; $end -= self::LIMB) {
            $start = max(0, $end - self::LIMB);
            $limbs[] = (int) substr($digits, $start, $end - $start);
        }

        return $limbs;
    }
}
