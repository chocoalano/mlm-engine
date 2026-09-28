<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Metrics;

use PandaBear\Mlm\Exceptions\InvalidMetricParameters;

/**
 * A metric a plan rule may name. Opt-in: a metric that implements only
 * `Metric` still resolves through the engine, but a plan version whose
 * rules name it cannot be validated.
 *
 * A plan stores a metric's key and parameters long before anything resolves
 * them, so the metric must be able to judge parameters on their own — for no
 * member, reading nothing.
 */
interface PlanConfigurableMetric extends Metric
{
    /**
     * Refuses parameters `resolve()` would refuse, and accepts those it would
     * accept: the same checks, without reading any data.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidMetricParameters
     */
    public function validatePlanParameters(array $parameters): void;
}
