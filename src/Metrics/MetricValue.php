<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Metrics;

use PandaBear\Mlm\Exceptions\InvalidMetric;
use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;
use PandaBear\Mlm\Volume\Quantity;
use Stringable;

/**
 * An exact metric result: a decimal with up to six places, or a whole count,
 * as its canonical string — "35", "0.3", "-50". Never a float.
 *
 * Callers need not know where a value came from; the exact arithmetic is the
 * package's Quantity, reused rather than repeated.
 */
final readonly class MetricValue implements Stringable
{
    private function __construct(private Quantity $quantity) {}

    /**
     * From a decimal string or an integer. A float is refused.
     */
    public static function of(mixed $value): self
    {
        try {
            return new self(Quantity::of($value));
        } catch (InvalidVolumeEntry $exception) {
            throw InvalidMetric::value($value, $exception);
        }
    }

    public static function fromQuantity(Quantity $quantity): self
    {
        return new self($quantity);
    }

    /**
     * The canonical decimal string.
     */
    public function value(): string
    {
        return $this->quantity->value();
    }

    public function equals(self $other): bool
    {
        return $this->quantity->equals($other->quantity);
    }

    public function __toString(): string
    {
        return $this->value();
    }
}
