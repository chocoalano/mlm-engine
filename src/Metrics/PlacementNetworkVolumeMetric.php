<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Metrics;

use PandaBear\Mlm\Exceptions\InvalidMetricParameters;

/**
 * `placement.network.volume`: the net volume of one type — the `type`
 * parameter — recorded by the members placed below the member, over the
 * context's effective range. The member's own volume is not included; that
 * is `member.volume`. Sponsorship plays no part.
 *
 * An entry counts only if its member was placed below the member when the
 * activity happened — for a reversal, when the reversed activity happened.
 * The optional `max_depth` (an integer of 1 or more) counts only members at
 * most that many placement steps down.
 *
 * Generic graph arithmetic, not a network type: no legs, sides, slots or
 * pairing.
 */
final readonly class PlacementNetworkVolumeMetric implements PlanConfigurableMetric
{
    public const KEY = 'placement.network.volume';

    private const PARAMETERS = ['type', 'max_depth'];

    public function __construct(private NetworkVolumeTotals $totals) {}

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @throws InvalidMetricParameters
     */
    public function resolve(MetricContext $context): MetricValue
    {
        [$type, $maxDepth] = $this->parameters($context->parameters);

        return MetricValue::fromQuantity($this->totals->forPlacementNetwork($context->member, $type, $maxDepth, $context->from, $context->until));
    }

    public function validatePlanParameters(array $parameters): void
    {
        $this->parameters($parameters);
    }

    /**
     * The one check both resolving and plan validation apply.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{string, int|null}
     */
    private function parameters(array $parameters): array
    {
        MetricParameters::refuseUnknown(self::KEY, $parameters, self::PARAMETERS);

        return [
            MetricParameters::volumeType(self::KEY, $parameters),
            MetricParameters::maxDepth(self::KEY, $parameters),
        ];
    }
}
