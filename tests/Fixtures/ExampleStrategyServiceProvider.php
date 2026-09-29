<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use PandaBear\Mlm\Commission\CommissionStrategyRegistry;

/**
 * How an application — or another package — adds its own commission
 * strategy, without touching this package.
 */
final class ExampleStrategyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(CommissionStrategyRegistry::class, static function (CommissionStrategyRegistry $strategies): void {
            $strategies->register(new FixedCommissionStrategy);
        });
    }
}
