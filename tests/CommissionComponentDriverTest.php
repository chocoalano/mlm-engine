<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionComponentDriver;
use PandaBear\Mlm\Commission\CommissionStrategy;
use PandaBear\Mlm\Commission\CommissionStrategyDefinition;
use PandaBear\Mlm\Commission\CommissionStrategyRegistry;
use PandaBear\Mlm\Commission\Strategies\DirectSponsorFixedStrategy;
use PandaBear\Mlm\Commission\Strategies\DirectSponsorProportionalStrategy;
use PandaBear\Mlm\Commission\Strategies\MatrixFixedStrategy;
use PandaBear\Mlm\Commission\Strategies\MatrixProportionalStrategy;
use PandaBear\Mlm\Commission\Strategies\UnilevelFixedStrategy;
use PandaBear\Mlm\Commission\Strategies\UnilevelProportionalStrategy;
use PandaBear\Mlm\Exceptions\DuplicateCommissionStrategy;
use PandaBear\Mlm\Exceptions\InvalidCommissionStrategy;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\UnknownCommissionStrategy;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Planning\PlanComponentDefinition;
use PandaBear\Mlm\Planning\PlanComponentDriverRegistry;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Fixtures\ExampleStrategyServiceProvider;
use PandaBear\Mlm\Tests\Fixtures\FixedCommissionStrategy;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The built-in commission component: four exact parameters selecting and
 * configuring a registered strategy, judged when its version is validated.
 * The package ships the component, never a strategy.
 */
final class CommissionComponentDriverTest extends DatabaseTestCase
{
    use BuildsCommissions;
    use BuildsLedgers;
    use BuildsPlanDefinitions;

    public function test_the_commission_component_and_the_sponsor_strategies_are_built_in(): void
    {
        $strategies = $this->app->make(CommissionStrategyRegistry::class);

        $this->assertInstanceOf(CommissionComponentDriver::class, $this->app->make(PlanComponentDriverRegistry::class)->get('commission.strategy'));
        $this->assertSame(['binary.pairing.fixed', 'binary.pairing.proportional', 'direct-sponsor.fixed', 'direct-sponsor.proportional', 'matrix.fixed', 'matrix.proportional', 'unilevel.fixed', 'unilevel.proportional'], $strategies->keys());
        $this->assertInstanceOf(DirectSponsorFixedStrategy::class, $strategies->get('direct-sponsor.fixed'));
        $this->assertInstanceOf(DirectSponsorProportionalStrategy::class, $strategies->get('direct-sponsor.proportional'));
        $this->assertInstanceOf(UnilevelFixedStrategy::class, $strategies->get('unilevel.fixed'));
        $this->assertInstanceOf(MatrixFixedStrategy::class, $strategies->get('matrix.fixed'));
        $this->assertInstanceOf(MatrixProportionalStrategy::class, $strategies->get('matrix.proportional'));
        $this->assertInstanceOf(UnilevelProportionalStrategy::class, $strategies->get('unilevel.proportional'));
        $this->assertSame($strategies, $this->app->make(CommissionStrategyRegistry::class));

        // A registry built by hand holds only what is registered into it.
        $this->assertSame([], (new CommissionStrategyRegistry)->keys());
    }

    public function test_no_application_strategy_replaces_a_built_in_one(): void
    {
        $strategies = $this->app->make(CommissionStrategyRegistry::class);
        $builtIn = $strategies->get('unilevel.fixed');
        $impostor = new class implements CommissionStrategy
        {
            public function key(): string
            {
                return 'unilevel.fixed';
            }

            public function validate(CommissionStrategyDefinition $definition): void {}

            public function calculate(CommissionCalculationContext $context): iterable
            {
                return [];
            }
        };

        try {
            $strategies->register($impostor);
            $this->fail('An application strategy replaced a built-in one.');
        } catch (DuplicateCommissionStrategy $exception) {
            $this->assertStringContainsString('"unilevel.fixed" is already registered', $exception->getMessage());
        }

        $this->assertSame($builtIn, $strategies->get('unilevel.fixed'));
    }

    public function test_a_component_of_a_registered_strategy_validates_and_the_strategy_judges_it(): void
    {
        $strategy = $this->fixedStrategy();
        $draft = $this->draft();
        $this->addCommissionComponent($draft, $this->commissionParameters(), rules: [
            'eligible' => RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100')),
        ]);

        $this->assertSame(PlanVersionStatus::Validated, $this->lifecycle()->markValidated($draft)->status);

        $definition = $strategy->validated[0];
        $this->assertSame(
            [$draft->id, 1, 'sales-commission', 'Sales commission', 'test.fixed', 'IDR', 'commission.payable', ['amount' => '10.5'], ['eligible']],
            [$definition->planVersionId, $definition->planVersion, $definition->componentKey, $definition->componentName, $definition->strategy, $definition->currency->value(), $definition->sourceAccount, $definition->parameters, array_map(static fn ($rule): string => $rule->key, $definition->rules)],
        );
    }

    public function test_an_unknown_strategy_can_be_drafted_but_not_validated(): void
    {
        $draft = $this->draft();
        $this->addCommissionComponent($draft, $this->commissionParameters(['strategy' => 'does.not.exist']));

        $this->assertRefused($draft, 'driver "commission.strategy": Invalid plan definition commission component: no commission strategy is registered under "does.not.exist".');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidParameters(): array
    {
        return [
            'a lowercase currency' => [['currency' => 'idr'], '"currency" is three uppercase ASCII letters'],
            'a currency that is not text' => [['currency' => 360], '"currency" is three uppercase ASCII letters'],
            'an uppercase source account' => [['source_account' => 'Commission.Payable'], '"source_account" is the key of a system ledger account'],
            'a wallet account as source' => [['source_account' => 'wallet.01abc'], 'not a wallet account'],
            'a class name as strategy' => [['strategy' => 'App\\Commission\\Referral'], '"strategy" is a commission strategy key'],
            'strategy parameters as a list' => [['parameters' => ['10.5']], '"parameters" is the strategy\'s own JSON object'],
            'strategy parameters as text' => [['parameters' => 'amount=10.5'], '"parameters" is the strategy\'s own JSON object'],
            'an unknown parameter' => [['formula' => 'amount * 0.1'], 'unknown: formula'],
            'the strategy\'s own parameters rejected' => [['parameters' => ['amount' => '-1']], 'parameter "amount": the fixed strategy pays a positive decimal "amount"'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidParameters')]
    public function test_invalid_parameters_are_drafted_but_block_validation(array $overrides, string $reason): void
    {
        $this->fixedStrategy();
        $draft = $this->draft();
        $this->addCommissionComponent($draft, $this->commissionParameters($overrides));

        $this->assertRefused($draft, $reason);
    }

    public function test_every_parameter_is_required(): void
    {
        $this->fixedStrategy();

        foreach (['strategy', 'currency', 'source_account', 'parameters'] as $field) {
            $parameters = $this->commissionParameters();
            unset($parameters[$field]);
            $draft = $this->draft();
            $this->addCommissionComponent($draft, $parameters);

            $this->assertRefused($draft, "missing: {$field}");
        }
    }

    public function test_the_driver_judges_only_commission_components_of_stored_versions(): void
    {
        $driver = $this->app->make(CommissionComponentDriver::class);

        foreach ([
            'its driver is "rank.ladder", not "commission.strategy"' => new PlanComponentDefinition('ranks', 'rank.ladder', 'Ranks', $this->commissionParameters(), 1, [], 'v', 1),
            'it is judged outside a stored plan version' => new PlanComponentDefinition('sales-commission', 'commission.strategy', 'Sales', $this->commissionParameters(), 1, []),
        ] as $reason => $component) {
            try {
                $driver->validate($component);
                $this->fail("Accepted: {$reason}.");
            } catch (InvalidPlanDefinition $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
        }
    }

    public function test_the_registry_holds_each_trusted_strategy_once(): void
    {
        $registry = new CommissionStrategyRegistry;
        $first = new FixedCommissionStrategy;
        $registry->register($first);

        $this->assertTrue($registry->has('test.fixed'));
        $this->assertSame($first, $registry->get('test.fixed'));
        $this->assertSame(['test.fixed'], $registry->keys());

        try {
            $registry->register(new FixedCommissionStrategy);
            $this->fail('A second strategy replaced the first.');
        } catch (DuplicateCommissionStrategy $exception) {
            $this->assertStringContainsString('"test.fixed" is already registered', $exception->getMessage());
        }

        $this->assertSame($first, $registry->get('test.fixed'));

        $this->expectException(UnknownCommissionStrategy::class);
        $this->expectExceptionMessage('No commission strategy is registered under "acme.missing". Registered: test.fixed.');

        $registry->get('acme.missing');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Acme.Referral'],
            'a class name' => ['App\\Strategies\\Referral'],
            'a trailing newline' => ["acme.referral\n"],
            'too long' => [str_repeat('a', 101)],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function test_an_invalid_strategy_key_is_refused(string $key): void
    {
        $strategy = new class($key) implements CommissionStrategy
        {
            public function __construct(private string $key) {}

            public function key(): string
            {
                return $this->key;
            }

            public function validate(CommissionStrategyDefinition $definition): void {}

            public function calculate(CommissionCalculationContext $context): iterable
            {
                return [];
            }
        };

        $this->expectException(InvalidCommissionStrategy::class);

        (new CommissionStrategyRegistry)->register($strategy);
    }

    public function test_an_application_registers_its_own_strategy_from_a_service_provider(): void
    {
        $registry = $this->app->make(CommissionStrategyRegistry::class);

        $this->app->register(ExampleStrategyServiceProvider::class);

        $this->assertSame(['binary.pairing.fixed', 'binary.pairing.proportional', 'direct-sponsor.fixed', 'direct-sponsor.proportional', 'matrix.fixed', 'matrix.proportional', 'test.fixed', 'unilevel.fixed', 'unilevel.proportional'], $registry->keys());

        $draft = $this->draft();
        $this->addCommissionComponent($draft, $this->commissionParameters());
        $this->assertSame(PlanVersionStatus::Validated, $this->lifecycle()->markValidated($draft)->status);
    }

    private function assertRefused(PlanVersion $draft, string $reason): void
    {
        try {
            $this->lifecycle()->markValidated($draft);
            $this->fail('An invalid commission component was validated.');
        } catch (InvalidPlanDefinition $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        $this->assertSame(PlanVersionStatus::Draft, $draft->refresh()->status);
    }
}
