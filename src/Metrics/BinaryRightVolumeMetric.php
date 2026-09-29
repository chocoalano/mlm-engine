<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Metrics;

use PandaBear\Mlm\Binary\BinaryLegVolumeTotals;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Exceptions\InvalidMetricParameters;

/**
 * `binary.right.volume`: the net volume of one type — the `type`
 * parameter — recorded in the member's right binary leg, over the context's
 * effective range: its right binary child and everyone below that child in
 * the binary tree. The member's own volume and its left leg are not
 * included, nor are members placed only generically.
 *
 * An entry counts only if its member was in the right leg when the activity
 * happened — for a reversal, when the reversed activity happened — so a side
 * assigned or a subtree adopted later never captures earlier activity. The
 * optional `max_depth` (an integer of 1 or more) counts from the member: 1
 * is the right child alone.
 *
 * Structure only: no pairing, carry or matching.
 */
final readonly class BinaryRightVolumeMetric implements PlanConfigurableMetric
{
    public const KEY = 'binary.right.volume';

    private const PARAMETERS = ['type', 'max_depth'];

    public function __construct(private BinaryLegVolumeTotals $totals) {}

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

        return MetricValue::fromQuantity($this->totals->forLeg($context->member, BinarySide::Right, $type, $maxDepth, $context->from, $context->until));
    }

    public function validatePlanParameters(array $parameters): void
    {
        $this->parameters($parameters);
    }

    /**
     * The one check both resolving and plan validation apply — the same as
     * every network metric's.
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
