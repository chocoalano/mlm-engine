<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Metrics;

use PandaBear\Mlm\Exceptions\InvalidMetricParameters;

/**
 * A named numeric fact about a member, computed on demand from the package's
 * source data. Not stored, and not a qualification: a metric answers "what
 * is the value", never "does the member qualify".
 *
 * Implementations are trusted code registered in the MetricRegistry at
 * bootstrap. A metric is never a class name, formula or SQL read from the
 * database; stored configuration may only ever name a registered key.
 */
interface Metric
{
    /**
     * The stable machine key the metric is registered and requested by,
     * e.g. "member.volume": 1–100 lowercase letters, digits, ".", "-" or "_".
     */
    public function key(): string;

    /**
     * The value for the context. Must not write, and must return the same
     * value for the same stored data and context.
     *
     * @throws InvalidMetricParameters for parameters the metric does not accept
     */
    public function resolve(MetricContext $context): MetricValue;
}
