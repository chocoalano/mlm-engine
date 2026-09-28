<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Metrics\MetricRegistry;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Tests\Fixtures\ExampleMetricServiceProvider;

/**
 * An application's service provider adds a metric to the real, container-
 * bound registry — no change to this package — and the real engine resolves
 * it like any built-in.
 */
final class CustomMetricRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ExampleMetricServiceProvider::class];
    }

    public function test_an_application_metric_resolves_through_the_engine(): void
    {
        $engine = $this->app->make(MetricEngine::class);

        $this->assertSame('12.5', $engine->resolve('custom.example', new MetricContext(new Member))->value());
        $this->assertSame('0.3', $engine->resolve('custom.example', new MetricContext(new Member, ['value' => '0.300']))->value());
    }

    public function test_the_built_ins_and_the_application_metric_sit_side_by_side(): void
    {
        $this->assertSame(['custom.example', 'member.volume'], $this->app->make(MetricRegistry::class)->keys());
    }
}
