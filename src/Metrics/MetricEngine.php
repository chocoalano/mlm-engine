<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Metrics;

use PandaBear\Mlm\Exceptions\UnknownMetric;

/**
 * Resolves a metric by key. Which code runs is the registry's answer alone;
 * the engine knows no metric by name.
 */
final readonly class MetricEngine
{
    public function __construct(private MetricRegistry $registry) {}

    /**
     * @throws UnknownMetric for a key nothing is registered under
     */
    public function resolve(string $key, MetricContext $context): MetricValue
    {
        return $this->registry->get($key)->resolve($context);
    }
}
