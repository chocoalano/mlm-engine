<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Fixtures;

use PandaBear\Mlm\Metrics\Metric;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricValue;

/**
 * A stand-in for an application's own metric: any key, and a value taken
 * from the context's "value" parameter, or a fixed default.
 */
final readonly class FixedMetric implements Metric
{
    public function __construct(
        private string $key,
        private string $value = '1',
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function resolve(MetricContext $context): MetricValue
    {
        return MetricValue::of($context->parameters['value'] ?? $this->value);
    }
}
