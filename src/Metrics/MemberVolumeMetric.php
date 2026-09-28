<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Metrics;

use PandaBear\Mlm\Exceptions\InvalidMetricParameters;
use PandaBear\Mlm\Volume\VolumeTotals;

/**
 * `member.volume`: the member's own net volume of one type — the `type`
 * parameter — over the context's effective range. Exactly
 * `VolumeTotals::forMember()`: reversals net out, the range is
 * [from, until). The member's genealogy plays no part.
 */
final readonly class MemberVolumeMetric implements PlanConfigurableMetric
{
    public const KEY = 'member.volume';

    private const PARAMETERS = ['type'];

    public function __construct(private VolumeTotals $totals) {}

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @throws InvalidMetricParameters
     */
    public function resolve(MetricContext $context): MetricValue
    {
        return MetricValue::fromQuantity(
            $this->totals->forMember($context->member, $this->type($context->parameters), $context->from, $context->until),
        );
    }

    public function validatePlanParameters(array $parameters): void
    {
        $this->type($parameters);
    }

    /**
     * The one check both resolving and plan validation apply.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function type(array $parameters): string
    {
        MetricParameters::refuseUnknown(self::KEY, $parameters, self::PARAMETERS);

        return MetricParameters::volumeType(self::KEY, $parameters);
    }
}
