<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Metrics\Metric;
use Throwable;

final class InvalidMetric extends DomainException
{
    public static function key(string $key, Metric $metric): self
    {
        return new self(sprintf(
            '%s declares the metric key "%s"; a key is 1–100 lowercase letters, digits, ".", "-" or "_", starting with a letter or digit.',
            $metric::class,
            $key,
        ));
    }

    public static function value(mixed $value, Throwable $previous): self
    {
        return new self('Not an exact metric value: '.$previous->getMessage(), previous: $previous);
    }
}
