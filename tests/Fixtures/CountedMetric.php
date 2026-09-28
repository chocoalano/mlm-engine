<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Fixtures;

use PandaBear\Mlm\Exceptions\InvalidMetricParameters;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricValue;
use PandaBear\Mlm\Metrics\PlanConfigurableMetric;

/**
 * A plan-configurable metric whose value is its own `value` parameter — an
 * exact decimal string — so a test can state what each condition sees. An
 * optional `label` tells conditions apart. It remembers every resolution, in
 * order.
 */
final class CountedMetric implements PlanConfigurableMetric
{
    /**
     * @var list<array{label: string|null, value: string, from: string|null, until: string|null}>
     */
    public array $resolutions = [];

    public function key(): string
    {
        return 'test.counted';
    }

    public function resolve(MetricContext $context): MetricValue
    {
        $this->validatePlanParameters($context->parameters);

        $this->resolutions[] = [
            'label' => $context->parameters['label'] ?? null,
            'value' => $context->parameters['value'],
            'from' => $context->from?->format('Y-m-d H:i:s'),
            'until' => $context->until?->format('Y-m-d H:i:s'),
        ];

        return MetricValue::of($context->parameters['value']);
    }

    public function validatePlanParameters(array $parameters): void
    {
        $unknown = array_values(array_diff(array_keys($parameters), ['value', 'label']));

        if ($unknown !== []) {
            throw InvalidMetricParameters::unknown($this->key(), $unknown, ['value', 'label']);
        }

        if (! is_string($parameters['value'] ?? null)) {
            throw InvalidMetricParameters::missing($this->key(), 'value');
        }
    }
}
