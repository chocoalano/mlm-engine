<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Fixtures;

use PandaBear\Mlm\Exceptions\InvalidMetricParameters;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricValue;
use PandaBear\Mlm\Metrics\PlanConfigurableMetric;

/**
 * A stand-in for an application's own plan-configurable metric: it takes one
 * required parameter, `window`, a whole number of days from 1 to 365.
 */
final readonly class ScoreMetric implements PlanConfigurableMetric
{
    public function key(): string
    {
        return 'acme.score';
    }

    public function resolve(MetricContext $context): MetricValue
    {
        $this->validatePlanParameters($context->parameters);

        return MetricValue::of('7');
    }

    public function validatePlanParameters(array $parameters): void
    {
        if (array_keys($parameters) !== ['window']) {
            throw InvalidMetricParameters::unknown($this->key(), array_values(array_diff(array_keys($parameters), ['window'])), ['window']);
        }

        if (! is_int($parameters['window']) || $parameters['window'] < 1 || $parameters['window'] > 365) {
            throw InvalidMetricParameters::invalid($this->key(), 'window', 'a window is 1 to 365 days.');
        }
    }
}
