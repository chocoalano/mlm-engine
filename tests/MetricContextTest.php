<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PandaBear\Mlm\Exceptions\InvalidMetricParameters;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Models\Member;

final class MetricContextTest extends TestCase
{
    public function test_its_range_is_normalised_to_application_moments_to_the_second(): void
    {
        $context = new MetricContext(
            new Member,
            from: CarbonImmutable::parse('2026-06-01 07:00:00', 'Asia/Jakarta'),
            until: CarbonImmutable::parse('2026-07-01 00:00:00.750'),
        );

        $this->assertSame('2026-06-01 00:00:00.000', $context->from?->format('Y-m-d H:i:s.v'));
        $this->assertSame('2026-07-01 00:00:00.000', $context->until?->format('Y-m-d H:i:s.v'));
    }

    public function test_both_bounds_may_be_open(): void
    {
        $context = new MetricContext(new Member);

        $this->assertNull($context->from);
        $this->assertNull($context->until);
        $this->assertSame([], $context->parameters);
    }

    public function test_an_empty_range_is_refused_as_volume_totals_refuse_it(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must start before it ends');

        new MetricContext(new Member, from: CarbonImmutable::parse('2026-07-01'), until: CarbonImmutable::parse('2026-06-01'));
    }

    public function test_parameters_must_be_named(): void
    {
        $this->expectException(InvalidMetricParameters::class);

        new MetricContext(new Member, ['sales']);
    }
}
