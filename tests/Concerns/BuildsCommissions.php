<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Concerns;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Calculation\CalculationContext;
use PandaBear\Mlm\Calculation\CalculationEngine;
use PandaBear\Mlm\Commission\CommissionLifecycle;
use PandaBear\Mlm\Commission\CommissionPoster;
use PandaBear\Mlm\Commission\CommissionStrategy;
use PandaBear\Mlm\Commission\CommissionStrategyRegistry;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Tests\Fixtures\FixedCommissionStrategy;
use PandaBear\Mlm\Tests\Fixtures\ScriptedCommissionStrategy;

/**
 * Commission components, runs and commissions built the supported way —
 * through the editor, the lifecycle, the engine and the poster.
 *
 * Uses BuildsPlanDefinitions and BuildsLedgers.
 */
trait BuildsCommissions
{
    protected function engine(): CalculationEngine
    {
        return $this->app->make(CalculationEngine::class);
    }

    protected function commissionLifecycle(): CommissionLifecycle
    {
        return $this->app->make(CommissionLifecycle::class);
    }

    protected function poster(): CommissionPoster
    {
        return $this->app->make(CommissionPoster::class);
    }

    protected function strategies(): CommissionStrategyRegistry
    {
        return $this->app->make(CommissionStrategyRegistry::class);
    }

    /**
     * The registered strategy under the key, registering this one if none is.
     *
     * @template TStrategy of CommissionStrategy
     *
     * @param  TStrategy  $strategy
     * @return TStrategy
     */
    protected function strategy(CommissionStrategy $strategy): CommissionStrategy
    {
        if (! $this->strategies()->has($strategy->key())) {
            $this->strategies()->register($strategy);
        }

        $registered = $this->strategies()->get($strategy->key());
        assert($registered instanceof $strategy);

        return $registered;
    }

    protected function fixedStrategy(): FixedCommissionStrategy
    {
        return $this->strategy(new FixedCommissionStrategy);
    }

    protected function scriptedStrategy(): ScriptedCommissionStrategy
    {
        return $this->strategy(new ScriptedCommissionStrategy);
    }

    /**
     * The parameters of a commission component paying the fixed strategy's
     * amount from "commission.payable" in IDR, with these replaced.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function commissionParameters(array $overrides = []): array
    {
        return [
            'strategy' => 'test.fixed',
            'currency' => 'IDR',
            'source_account' => 'commission.payable',
            'parameters' => ['amount' => '10.5'],
            ...$overrides,
        ];
    }

    /**
     * A commission component added to the draft through the editor.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, RuleDefinition>  $rules
     */
    protected function addCommissionComponent(PlanVersion $draft, array $parameters, string $key = 'sales-commission', array $rules = []): PlanComponent
    {
        $component = $this->editor()->addComponent($draft, $key, 'commission.strategy', 'Sales commission', $parameters);

        foreach ($rules as $ruleKey => $definition) {
            $this->editor()->addRule($component, $ruleKey, ucfirst($ruleKey), $definition);
        }

        return PlanComponent::query()->findOrFail($component->id);
    }

    /**
     * A commission component of a validated version of `$plan`, with its
     * source account open: ready to calculate.
     *
     * @param  array<string, mixed>  $overrides  component parameters
     * @param  array<string, RuleDefinition>  $rules
     */
    protected function commissionComponent(array $overrides = [], ?Plan $plan = null, array $rules = []): PlanComponent
    {
        $this->fixedStrategy();
        $plan ??= Plan::factory()->create();
        $parameters = $this->commissionParameters($overrides);

        $this->systemAccounts()->openSystemAccount($plan->program, $parameters['currency'], $parameters['source_account']);

        $draft = $this->draft($plan);
        $component = $this->addCommissionComponent($draft, $parameters, rules: $rules);
        $this->lifecycle()->markValidated($draft);

        return $component;
    }

    protected function calculate(PlanComponent $component, string $from = '2026-06-01 00:00:00', string $until = '2026-07-01 00:00:00', string $key = 'run:2026-06'): CalculationRun
    {
        return $this->engine()->calculate($component, new CalculationContext(CarbonImmutable::parse($from), CarbonImmutable::parse($until), $key));
    }

    /**
     * A commission of a fresh run, brought to APPROVED through the lifecycle.
     */
    protected function approved(Commission $commission): Commission
    {
        return $this->commissionLifecycle()->approve($this->commissionLifecycle()->markPending($commission));
    }
}
