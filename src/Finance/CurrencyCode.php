<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Finance;

use PandaBear\Mlm\Exceptions\InvalidCurrencyCode;
use Stringable;

/**
 * A currency, as three uppercase ASCII letters: "IDR", "USD". Only the shape
 * is checked — the package ships no list of currencies; which ones a
 * business uses is its own decision. Nothing is uppercased or trimmed on the
 * caller's behalf.
 */
final readonly class CurrencyCode implements Stringable
{
    private function __construct(private string $code) {}

    public static function of(string $code): self
    {
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
            throw InvalidCurrencyCode::shape($code);
        }

        return new self($code);
    }

    public static function from(self|string $code): self
    {
        return $code instanceof self ? $code : self::of($code);
    }

    public function value(): string
    {
        return $this->code;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
