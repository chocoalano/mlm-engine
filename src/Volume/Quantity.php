<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Volume;

use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;
use Stringable;

/**
 * An exact decimal quantity, held as its canonical string: no leading zeros,
 * no trailing fractional zeros, no sign on zero — "25", "25.5", "-0.125".
 *
 * Never a float. Stored as an integer count of millionths, so six decimal
 * places is the precision; a value with more is refused, never rounded.
 */
final readonly class Quantity implements Stringable
{
    /**
     * Decimal places the package stores.
     */
    public const SCALE = 6;

    /**
     * Integer digits one recorded quantity may have: at most
     * 999,999,999,999.999999, which fits a 64-bit count of millionths.
     */
    public const MAX_INTEGER_DIGITS = 12;

    private function __construct(private string $value) {}

    /**
     * A decimal string such as "25.125", or an integer. A float is refused:
     * most decimals have no exact binary representation.
     */
    public static function of(mixed $value): self
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            throw InvalidVolumeEntry::quantity($value, is_float($value)
                ? 'a float cannot hold most decimals exactly — pass a string such as "1.1"'
                : 'it must be a decimal string or an integer');
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $parts) !== 1) {
            throw InvalidVolumeEntry::quantity($value, 'it is not a plain decimal such as "25" or "-0.125"');
        }

        $integer = ltrim($parts[2], '0');
        $fraction = rtrim($parts[3] ?? '', '0');

        if (strlen($fraction) > self::SCALE) {
            throw InvalidVolumeEntry::quantity($value, 'it has more than '.self::SCALE.' decimal places, and is not rounded');
        }

        if (strlen($integer) > self::MAX_INTEGER_DIGITS) {
            throw InvalidVolumeEntry::quantity($value, 'it has more than '.self::MAX_INTEGER_DIGITS.' integer digits');
        }

        return self::canonical($parts[1], $integer, $fraction);
    }

    /**
     * From an integer count of millionths — the stored form, and the form a
     * database sum returns. Given as a string, it may exceed a 64-bit integer.
     */
    public static function fromMillionths(int|string $millionths): self
    {
        if (preg_match('/^(-?)(\d+)$/', (string) $millionths, $parts) !== 1) {
            throw InvalidVolumeEntry::quantity($millionths, 'it is not a whole number of millionths');
        }

        $digits = str_pad($parts[2], self::SCALE + 1, '0', STR_PAD_LEFT);

        return self::canonical(
            $parts[1],
            ltrim(substr($digits, 0, -self::SCALE), '0'),
            rtrim(substr($digits, -self::SCALE), '0'),
        );
    }

    /**
     * The stored form. Exact for any quantity `of()` accepts.
     */
    public function toMillionths(): int
    {
        [$integer, $fraction] = explode('.', ltrim($this->value, '-')) + [1 => ''];

        $millionths = (int) ($integer.str_pad($fraction, self::SCALE, '0'));

        return $this->isNegative() ? -$millionths : $millionths;
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
}
