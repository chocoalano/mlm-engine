<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Metrics;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PandaBear\Mlm\Exceptions\InvalidMetricParameters;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Volume\VolumeInput;

/**
 * What a metric is resolved for: a member, the metric's own parameters, and
 * an optional effective range `[from, until)` — the same range rule volume
 * totals use. Time enters only through these bounds.
 */
final readonly class MetricContext
{
    public ?CarbonImmutable $from;

    public ?CarbonImmutable $until;

    /**
     * @param  array<string, mixed>  $parameters  the metric's own inputs; each metric validates the ones it accepts and refuses any other
     */
    public function __construct(
        public Member $member,
        public array $parameters = [],
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null,
    ) {
        foreach (array_keys($parameters) as $name) {
            if (! is_string($name) || $name === '') {
                throw InvalidMetricParameters::unnamed($name);
            }
        }

        [$this->from, $this->until] = VolumeInput::range($from, $until);
    }
}
