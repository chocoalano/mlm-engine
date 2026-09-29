<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Finance;

use PandaBear\Mlm\Exceptions\InvalidFinancialAmount;
use Stringable;

/**
 * An exact amount of money, held as its canonical decimal string: no leading
 * zeros, no trailing fractional zeros, no sign on zero — "100", "100.5",
 * "-25.123456".
 *
 * Never a float, and never rounded: six decimal places is the precision, and
 * a value with more is refused. There is no upper bound — a balance may be
 * far larger than a 64-bit integer and stays exact; how large one posting
 * may be is the ledger's rule, not this value's. Arithmetic is exact, on
 * decimal strings, with no extension required.
 *
 * Its own value, not the volume domain's quantity: money and volume are kept
 * apart.
 */
final readonly class FinancialAmount implements Stringable
{
    /**
     * Decimal places the ledger stores.
     */
    public const SCALE = 6;

    /**
     * Digits added or subtracted at a time: a limb stays far inside a 64-bit
     * integer, carries included.
     */
    private const LIMB = 9;

    private function __construct(private string $value) {}

    /**
     * A decimal string such as "100.25", or an integer. A float is refused —
     * most decimals have no exact binary form — as are exponents, signs
     * other than a leading "-", and surrounding whitespace.
     *
     * @param  string|int  $value
     */
    public static function of(mixed $value): self
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            throw InvalidFinancialAmount::of($value, is_float($value)
                ? 'a float cannot hold most decimals exactly — pass a string such as "0.1"'
                : 'it must be a decimal string or an integer');
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $value, $parts) !== 1) {
            throw InvalidFinancialAmount::of($value, 'it is not a plain decimal such as "100" or "-0.125"');
        }

        $fraction = rtrim($parts[3] ?? '', '0');

        if (strlen($fraction) > self::SCALE) {
            throw InvalidFinancialAmount::of($value, 'it has more than '.self::SCALE.' decimal places, and is not rounded');
        }

        return self::canonical($parts[1], ltrim($parts[2], '0'), $fraction);
    }

    /**
     * From a whole number of millionths — the stored form. As a string it may
     * exceed a 64-bit integer.
     */
    public static function fromMillionths(int|string $millionths): self
    {
        if (preg_match('/^(-?)(\d+)$/D', (string) $millionths, $parts) !== 1) {
            throw InvalidFinancialAmount::of($millionths, 'it is not a whole number of millionths');
        }

        $digits = str_pad($parts[2], self::SCALE + 1, '0', STR_PAD_LEFT);

        return self::canonical(
            $parts[1],
            ltrim(substr($digits, 0, -self::SCALE), '0'),
            rtrim(substr($digits, -self::SCALE), '0'),
        );
    }

    public static function zero(): self
    {
        return new self('0');
    }

    /**
     * The exact total of `$amounts`; zero for none.
     *
     * @param  iterable<self>  $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = '0';

        foreach ($amounts as $amount) {
            if (! $amount instanceof self) {
                throw InvalidFinancialAmount::of($amount, 'only financial amounts can be summed');
            }

            $total = self::addIntegers($total, $amount->toMillionths());
        }

        return self::fromMillionths($total);
    }

    /**
     * The amount as a whole number of millionths, as an exact signed integer
     * string — "100500000" for 100.5 — whatever its size: never a PHP int,
     * which would overflow.
     */
    public function toMillionths(): string
    {
        [$integer, $fraction] = explode('.', ltrim($this->value, '-')) + [1 => ''];

        $digits = ltrim($integer.str_pad($fraction, self::SCALE, '0'), '0');

        if ($digits === '') {
            return '0';
        }

        return ($this->isNegative() ? '-' : '').$digits;
    }

    public function add(self $other): self
    {
        return self::fromMillionths(self::addIntegers($this->toMillionths(), $other->toMillionths()));
    }

    public function negate(): self
    {
        if ($this->isZero()) {
            return $this;
        }

        return new self($this->isNegative() ? substr($this->value, 1) : '-'.$this->value);
    }

    public function isZero(): bool
    {
        return $this->value === '0';
    }

    public function isNegative(): bool
    {
        return str_starts_with($this->value, '-');
    }

    public function isPositive(): bool
    {
        return ! $this->isZero() && ! $this->isNegative();
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * -1, 0 or 1 as this amount is below, equal to or above `$other`,
     * compared exactly, at any size.
     */
    public function compare(self $other): int
    {
        if ($this->isNegative() !== $other->isNegative()) {
            return $this->isNegative() ? -1 : 1;
        }

        $magnitude = self::compareMagnitudes(ltrim($this->toMillionths(), '-'), ltrim($other->toMillionths(), '-'));

        return $this->isNegative() ? -$magnitude : $magnitude;
    }

    /**
     * The canonical decimal string.
     */
    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function canonical(string $sign, string $integer, string $fraction): self
    {
        $value = ($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);

        return new self($value === '0' ? '0' : $sign.$value);
    }

    /**
     * The exact sum of two canonical signed integer strings.
     */
    private static function addIntegers(string $a, string $b): string
    {
        $aNegative = str_starts_with($a, '-');
        $bNegative = str_starts_with($b, '-');
        [$aDigits, $bDigits] = [ltrim($a, '-'), ltrim($b, '-')];

        // Below 10^18 each, the sum fits a 64-bit integer.
        if (strlen($aDigits) < 19 && strlen($bDigits) < 19) {
            return (string) ((int) $a + (int) $b);
        }

        if ($aNegative === $bNegative) {
            return ($aNegative ? '-' : '').self::addMagnitudes($aDigits, $bDigits);
        }

        return match (self::compareMagnitudes($aDigits, $bDigits)) {
            0 => '0',
            1 => ($aNegative ? '-' : '').self::subtractMagnitudes($aDigits, $bDigits),
            -1 => ($bNegative ? '-' : '').self::subtractMagnitudes($bDigits, $aDigits),
        };
    }

    private static function compareMagnitudes(string $a, string $b): int
    {
        return (strlen($a) <=> strlen($b)) ?: (strcmp($a, $b) <=> 0);
    }

    private static function addMagnitudes(string $a, string $b): string
    {
        [$a, $b] = [self::limbs($a), self::limbs($b)];
        $sum = [];
        $carry = 0;

        for ($i = 0, $count = max(count($a), count($b)); $i < $count; $i++) {
            $limb = ($a[$i] ?? 0) + ($b[$i] ?? 0) + $carry;
            $carry = intdiv($limb, 10 ** self::LIMB);
            $sum[] = $limb % 10 ** self::LIMB;
        }

        if ($carry > 0) {
            $sum[] = $carry;
        }

        return self::digits($sum);
    }

    /**
     * `$a - $b`, for `$a` at least `$b`.
     */
    private static function subtractMagnitudes(string $a, string $b): string
    {
        [$a, $b] = [self::limbs($a), self::limbs($b)];
        $difference = [];
        $borrow = 0;

        foreach ($a as $i => $limb) {
            $limb -= ($b[$i] ?? 0) + $borrow;
            $borrow = $limb < 0 ? 1 : 0;
            $difference[] = $limb + $borrow * 10 ** self::LIMB;
        }

        return self::digits($difference);
    }

    /**
     * @return list<int> the digits in limbs, least significant first
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

    /**
     * @param  list<int>  $limbs  least significant first
     */
    private static function digits(array $limbs): string
    {
        $digits = '';

        foreach (array_reverse($limbs) as $limb) {
            $digits .= str_pad((string) $limb, self::LIMB, '0', STR_PAD_LEFT);
        }

        $digits = ltrim($digits, '0');

        return $digits === '' ? '0' : $digits;
    }
}
