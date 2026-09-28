<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use PandaBear\Mlm\Exceptions\DuplicatePlanComponentDriver;
use PandaBear\Mlm\Exceptions\InvalidPlanComponentDriver;
use PandaBear\Mlm\Exceptions\UnknownPlanComponentDriver;
use PandaBear\Mlm\Planning\PlanComponentDriverRegistry;
use PandaBear\Mlm\Rank\RankLadderDriver;
use PandaBear\Mlm\Tests\Fixtures\CriteriaDriver;
use PandaBear\Mlm\Tests\Fixtures\ExampleDriverServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;

final class PlanComponentDriverRegistryTest extends TestCase
{
    public function test_the_package_registers_its_rank_ladder_and_nothing_else(): void
    {
        $registry = $this->app->make(PlanComponentDriverRegistry::class);

        $this->assertSame(['rank.ladder'], $registry->keys());
        $this->assertInstanceOf(RankLadderDriver::class, $registry->get('rank.ladder'));
    }

    public function test_no_application_driver_replaces_the_rank_ladder(): void
    {
        $registry = $this->app->make(PlanComponentDriverRegistry::class);
        $builtIn = $registry->get('rank.ladder');

        try {
            $registry->register(new CriteriaDriver('rank.ladder'));
            $this->fail('An application driver replaced the rank ladder.');
        } catch (DuplicatePlanComponentDriver $exception) {
            $this->assertStringContainsString('"rank.ladder" is already registered', $exception->getMessage());
        }

        $this->assertSame($builtIn, $registry->get('rank.ladder'));
    }

    public function test_the_registry_is_one_instance_for_the_application(): void
    {
        $this->assertSame($this->app->make(PlanComponentDriverRegistry::class), $this->app->make(PlanComponentDriverRegistry::class));
    }

    public function test_a_registered_driver_is_found_by_its_key(): void
    {
        $registry = new PlanComponentDriverRegistry;
        $driver = new CriteriaDriver('acme.bonus');

        $registry->register($driver);

        $this->assertTrue($registry->has('acme.bonus'));
        $this->assertSame($driver, $registry->get('acme.bonus'));
    }

    public function test_keys_are_listed_in_order(): void
    {
        $registry = new PlanComponentDriverRegistry;

        foreach (['zeta.one', 'alpha.two', 'mid.three'] as $key) {
            $registry->register(new CriteriaDriver($key));
        }

        $this->assertSame(['alpha.two', 'mid.three', 'zeta.one'], $registry->keys());
    }

    public function test_a_key_is_registered_once_and_never_replaced(): void
    {
        $registry = new PlanComponentDriverRegistry;
        $first = new CriteriaDriver('acme.bonus');
        $registry->register($first);

        try {
            $registry->register(new CriteriaDriver('acme.bonus'));
            $this->fail('A second driver replaced the first.');
        } catch (DuplicatePlanComponentDriver $exception) {
            $this->assertStringContainsString('"acme.bonus" is already registered', $exception->getMessage());
        }

        $this->assertSame($first, $registry->get('acme.bonus'));
    }

    public function test_an_unknown_key_is_refused(): void
    {
        $registry = new PlanComponentDriverRegistry;
        $registry->register(new CriteriaDriver('acme.bonus'));

        $this->assertFalse($registry->has('acme.missing'));
        $this->expectException(UnknownPlanComponentDriver::class);
        $this->expectExceptionMessage('No plan component driver is registered under "acme.missing". Registered: acme.bonus.');

        $registry->get('acme.missing');
    }

    public function test_keys_compare_exactly(): void
    {
        $registry = new PlanComponentDriverRegistry;
        $registry->register(new CriteriaDriver('acme.bonus'));

        $this->assertFalse($registry->has('acme.bonus '));
        $this->assertFalse($registry->has('ACME.BONUS'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Acme.Bonus'],
            'a class name' => ['App\\Drivers\\Bonus'],
            'a space' => ['acme bonus'],
            'leading dot' => ['.acme'],
            'too long' => [str_repeat('a', 101)],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function test_an_invalid_key_is_refused(string $key): void
    {
        $this->expectException(InvalidPlanComponentDriver::class);

        (new PlanComponentDriverRegistry)->register(new CriteriaDriver($key));
    }

    public function test_the_longest_valid_key_is_accepted(): void
    {
        $registry = new PlanComponentDriverRegistry;
        $registry->register(new CriteriaDriver(str_repeat('a', 100)));

        $this->assertTrue($registry->has(str_repeat('a', 100)));
    }

    public function test_an_application_registers_its_own_driver_from_a_service_provider(): void
    {
        $registry = $this->app->make(PlanComponentDriverRegistry::class);

        $this->app->register(ExampleDriverServiceProvider::class);

        $this->assertSame(['acme.example', 'rank.ladder'], $registry->keys());
        $this->assertInstanceOf(CriteriaDriver::class, $registry->get('acme.example'));
    }
}
