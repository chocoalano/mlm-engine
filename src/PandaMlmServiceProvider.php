<?php

declare(strict_types=1);

namespace PandaBear\Mlm;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PandaBear\Mlm\Metrics\MemberVolumeMetric;
use PandaBear\Mlm\Metrics\MetricRegistry;
use PandaBear\Mlm\Support\PandaMlmConfig;

final class PandaMlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mlm.php', 'mlm');

        // Not shared: building it is five config reads, and a fresh instance
        // per resolution means a config change is never masked by a stale one.
        $this->app->bind(
            PandaMlmConfig::class,
            static fn (Application $app): PandaMlmConfig => PandaMlmConfig::fromConfig($app->make(Repository::class)),
        );

        // Shared: registrations are application configuration, made once at
        // boot, and resolving a metric never changes them. The built-ins go
        // through the same register() any application uses.
        $this->app->singleton(MetricRegistry::class, static function (Application $app): MetricRegistry {
            $registry = new MetricRegistry;
            $registry->register($app->make(MemberVolumeMetric::class));

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
