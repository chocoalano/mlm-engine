<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Metrics;

use PandaBear\Mlm\Exceptions\InvalidMetricParameters;

/**
 * `matrix.network.volume` (ADR-027): the net volume of one type — the
 * `type` parameter — recorded by the members below the member in the
 * matrix, over the context's effective range. The member's own volume is
 * not included, nor are members placed only generically; sponsorship and
 * the binary tree play no part.
 *
 * An entry counts only if its member was below the member in the matrix
 * when the activity happened — for a reversal, when the reversed activity
 * happened — so an edge adopted later never captures earlier activity. The
 * optional `max_depth` (an integer of 1 or more) counts from the member: 1
 * is its direct matrix children alone.
 *
 * Structure only: no cycles, boards or completion.
 */
final readonly class MatrixNetworkVolumeMetric implements PlanConfigurableMetric
{
    public const KEY = 'matrix.network.volume';

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

        return MetricValue::fromQuantity($this->totals->forMatrixNetwork($context->member, $type, $maxDepth, $context->from, $context->until));
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
