<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Metrics;

use PandaBear\Mlm\Exceptions\InvalidMetricParameters;

/**
 * `sponsor.network.volume`: the net volume of one type — the `type`
 * parameter — recorded by the members below the member in the sponsor tree,
 * over the context's effective range. The member's own volume is not
 * included; that is `member.volume`.
 *
 * An entry counts only if its member was below the member in the sponsor
 * tree when the activity happened — for a reversal, when the reversed
 * activity happened. The optional `max_depth` (an integer of 1 or more)
 * counts only members at most that many sponsorships down.
 *
 * Generic graph arithmetic, not a compensation plan: no generations, legs or
 * qualification.
 */
final readonly class SponsorNetworkVolumeMetric implements Metric
{
    public const KEY = 'sponsor.network.volume';

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
        MetricParameters::refuseUnknown(self::KEY, $context->parameters, self::PARAMETERS);

        return MetricValue::fromQuantity($this->totals->forSponsorNetwork(
            $context->member,
            MetricParameters::volumeType(self::KEY, $context->parameters),
            MetricParameters::maxDepth(self::KEY, $context->parameters),
            $context->from,
            $context->until,
        ));
    }
}
