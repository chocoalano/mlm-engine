<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use PandaBear\Mlm\Metrics\MetricRegistry;

/**
 * How an application — or another package — adds its own metric, without
 * touching this package.
 */
final class ExampleMetricServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(MetricRegistry::class, static function (MetricRegistry $metrics): void {
            $metrics->register(new FixedMetric('custom.example', '12.5'));
        });
    }
}
