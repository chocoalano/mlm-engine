<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Metrics;

use PandaBear\Mlm\Exceptions\InvalidMetricParameters;
use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;
use PandaBear\Mlm\Volume\VolumeInput;
use PandaBear\Mlm\Volume\VolumeTotals;

/**
 * `member.volume`: the member's own net volume of one type — the `type`
 * parameter — over the context's effective range. Exactly
 * `VolumeTotals::forMember()`: reversals net out, the range is
 * [from, until). The member's genealogy plays no part.
 */
final readonly class MemberVolumeMetric implements Metric
{
    public const KEY = 'member.volume';

    private const PARAMETERS = ['type'];

    public function __construct(private VolumeTotals $totals) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function resolve(MetricContext $context): MetricValue
    {
        $unknown = array_values(array_diff(array_keys($context->parameters), self::PARAMETERS));

        if ($unknown !== []) {
            throw InvalidMetricParameters::unknown(self::KEY, $unknown, self::PARAMETERS);
        }

        if (! array_key_exists('type', $context->parameters)) {
            throw InvalidMetricParameters::missing(self::KEY, 'type');
        }

        $type = $context->parameters['type'];

        if (! is_string($type)) {
            throw InvalidMetricParameters::invalid(self::KEY, 'type', 'a volume type is a string, '.get_debug_type($type).' given.');
        }

        try {
            VolumeInput::identifier('type', $type);
        } catch (InvalidVolumeEntry $exception) {
            throw InvalidMetricParameters::invalid(self::KEY, 'type', $exception->getMessage());
        }

        return MetricValue::fromQuantity(
            $this->totals->forMember($context->member, $type, $context->from, $context->until),
        );
    }
}
