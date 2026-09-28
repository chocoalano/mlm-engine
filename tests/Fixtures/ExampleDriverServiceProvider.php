<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use PandaBear\Mlm\Planning\PlanComponentDriverRegistry;

/**
 * How an application — or another package — adds its own component driver,
 * without touching this package.
 */
final class ExampleDriverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(PlanComponentDriverRegistry::class, static function (PlanComponentDriverRegistry $drivers): void {
            $drivers->register(new CriteriaDriver('acme.example'));
        });
    }
}
