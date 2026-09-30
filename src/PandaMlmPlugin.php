<?php

declare(strict_types=1);

namespace PandaBear\Mlm;

use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Pages\MlmOverview;
use PandaBear\Mlm\Panel\Resources\Calculations\CalculationRunResource;
use PandaBear\Mlm\Panel\Resources\Commissions\CommissionResource;
use PandaBear\Mlm\Panel\Resources\Members\MemberResource;
use PandaBear\Mlm\Panel\Resources\Payouts\PayoutBatchResource;
use PandaBear\Mlm\Panel\Resources\Payouts\PayoutRequestResource;
use PandaBear\Mlm\Panel\Resources\Periods\CommissionPeriodResource;
use PandaBear\Mlm\Panel\Resources\Plans\PlanResource;
use PandaBear\Mlm\Panel\Resources\Plans\PlanVersionResource;
use PandaBear\Mlm\Panel\Resources\Programs\ProgramResource;
use PandaBear\Mlm\Panel\Resources\Wallets\WalletResource;
use PandaPanel\Contracts\PanelPlugin;
use PandaPanel\Core\Panel;
use PandaPanel\Plugins\PluginMetadata;

/**
 * The Panda Panel operational surface of Panda MLM (ADR-031).
 *
 * An adapter only: every resource reads the domain's models, and every
 * lifecycle action calls a domain service. The domain itself never depends
 * on Panda Panel.
 */
final class PandaMlmPlugin implements PanelPlugin
{
    /**
     * The resources this plugin registers, in sidebar order.
     *
     * @var list<class-string>
     */
    public const RESOURCES = [
        ProgramResource::class,
        MemberResource::class,
        PlanResource::class,
        PlanVersionResource::class,
        CommissionPeriodResource::class,
        CalculationRunResource::class,
        CommissionResource::class,
        WalletResource::class,
        PayoutRequestResource::class,
        PayoutBatchResource::class,
    ];

    /**
     * @var list<class-string>
     */
    public const PAGES = [
        MlmOverview::class,
    ];

    public static function make(): self
    {
        return new self;
    }

    public function id(): string
    {
        return 'panda-mlm';
    }

    /**
     * Classes only — nothing queries, resolves a route or reads the user
     * while a panel is being configured.
     */
    public function register(Panel $panel): void
    {
        $panel
            ->resources(self::RESOURCES)
            ->pages(self::PAGES);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public function metadata(): PluginMetadata
    {
        return new PluginMetadata(
            name: 'Panda MLM',
            package: 'pandabear/mlm',
            requiresPanel: '^0.5.7',
        );
    }

    /**
     * Nothing to copy: the surface uses the panel's own components, and its
     * translations are read from the package (an application overrides them
     * under `lang/vendor/mlm`).
     *
     * @return array<string, string>
     */
    public function publishes(): array
    {
        return [];
    }

    /**
     * The capabilities the surface asks for, for an application to grant.
     * Panda Panel has no permission discovery of its own.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return MlmPermission::all();
    }
}
