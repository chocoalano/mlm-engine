<?php

declare(strict_types=1);

namespace PandaBear\Mlm;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PandaBear\Mlm\Binary\Pairing\BinaryPairingFixedStrategy;
use PandaBear\Mlm\Binary\Pairing\BinaryPairingProportionalStrategy;
use PandaBear\Mlm\Commission\CommissionComponentDriver;
use PandaBear\Mlm\Commission\CommissionStrategyRegistry;
use PandaBear\Mlm\Commission\Strategies\DirectSponsorFixedStrategy;
use PandaBear\Mlm\Commission\Strategies\DirectSponsorProportionalStrategy;
use PandaBear\Mlm\Commission\Strategies\MatrixFixedStrategy;
use PandaBear\Mlm\Commission\Strategies\MatrixProportionalStrategy;
use PandaBear\Mlm\Commission\Strategies\UnilevelFixedStrategy;
use PandaBear\Mlm\Commission\Strategies\UnilevelProportionalStrategy;
use PandaBear\Mlm\Metrics\BinaryLeftVolumeMetric;
use PandaBear\Mlm\Metrics\BinaryRightVolumeMetric;
use PandaBear\Mlm\Metrics\MatrixNetworkVolumeMetric;
use PandaBear\Mlm\Metrics\MemberVolumeMetric;
use PandaBear\Mlm\Metrics\MetricRegistry;
use PandaBear\Mlm\Metrics\PlacementNetworkVolumeMetric;
use PandaBear\Mlm\Metrics\SponsorNetworkVolumeMetric;
use PandaBear\Mlm\Planning\PlanComponentDriverRegistry;
use PandaBear\Mlm\Planning\PlanDefinitionCloner;
use PandaBear\Mlm\Planning\PlanDefinitionEditor;
use PandaBear\Mlm\Planning\PlanDefinitionValidator;
use PandaBear\Mlm\Rank\RankLadderDriver;
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
            $registry->register($app->make(SponsorNetworkVolumeMetric::class));
            $registry->register($app->make(PlacementNetworkVolumeMetric::class));
            $registry->register($app->make(BinaryLeftVolumeMetric::class));
            $registry->register($app->make(BinaryRightVolumeMetric::class));
            $registry->register($app->make(MatrixNetworkVolumeMetric::class));

            return $registry;
        });

        // Shared for the same reason. The built-in rank ladder and commission
        // component go through the same register() applications use for
        // their own drivers.
        $this->app->singleton(PlanComponentDriverRegistry::class, static function (Application $app): PlanComponentDriverRegistry {
            $registry = new PlanComponentDriverRegistry;
            $registry->register($app->make(RankLadderDriver::class));
            $registry->register($app->make(CommissionComponentDriver::class));

            return $registry;
        });

        // Shared for the same reason. The built-in strategies go through the
        // same register() applications use for their own.
        $this->app->singleton(CommissionStrategyRegistry::class, static function (Application $app): CommissionStrategyRegistry {
            $registry = new CommissionStrategyRegistry;
            $registry->register($app->make(DirectSponsorFixedStrategy::class));
            $registry->register($app->make(DirectSponsorProportionalStrategy::class));
            $registry->register($app->make(UnilevelFixedStrategy::class));
            $registry->register($app->make(UnilevelProportionalStrategy::class));
            $registry->register($app->make(BinaryPairingFixedStrategy::class));
            $registry->register($app->make(BinaryPairingProportionalStrategy::class));
            $registry->register($app->make(MatrixFixedStrategy::class));
            $registry->register($app->make(MatrixProportionalStrategy::class));

            return $registry;
        });

        // Stateless services over the shared registries and the configured
        // connection.
        $this->app->singleton(PlanDefinitionValidator::class);
        $this->app->singleton(PlanDefinitionEditor::class);
        $this->app->singleton(PlanDefinitionCloner::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // The Panda Panel surface's words, in English and Indonesian. Read
        // from the package — an application overrides one under
        // `lang/vendor/mlm` without publishing anything.
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'mlm');
    }
}
