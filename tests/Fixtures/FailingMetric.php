<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Fixtures;

use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricValue;
use PandaBear\Mlm\Metrics\PlanConfigurableMetric;
use RuntimeException;

/**
 * A plan-configurable metric that accepts its parameters but cannot be
 * resolved: a stand-in for a metric whose data source has failed.
 */
final readonly class FailingMetric implements PlanConfigurableMetric
{
    public function key(): string
    {
        return 'test.failing';
    }

    public function resolve(MetricContext $context): MetricValue
    {
        throw new RuntimeException('The metric\'s data source is unavailable.');
    }

    public function validatePlanParameters(array $parameters): void {}
}
