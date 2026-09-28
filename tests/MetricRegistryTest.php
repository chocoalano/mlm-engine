<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use PandaBear\Mlm\Exceptions\DuplicateMetric;
use PandaBear\Mlm\Exceptions\InvalidMetric;
use PandaBear\Mlm\Exceptions\UnknownMetric;
use PandaBear\Mlm\Metrics\MemberVolumeMetric;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Metrics\MetricRegistry;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Tests\Fixtures\ExampleMetricServiceProvider;
use PandaBear\Mlm\Tests\Fixtures\FixedMetric;
use PHPUnit\Framework\Attributes\DataProvider;

final class MetricRegistryTest extends TestCase
{
    public function test_the_package_registers_member_volume_as_a_built_in(): void
    {
        $registry = $this->app->make(MetricRegistry::class);

        $this->assertSame(['member.volume'], $registry->keys());
        $this->assertInstanceOf(MemberVolumeMetric::class, $registry->get('member.volume'));
    }

    public function test_the_registry_is_one_instance_for_the_application(): void
    {
        $this->assertSame($this->app->make(MetricRegistry::class), $this->app->make(MetricRegistry::class));
    }

    public function test_a_registered_metric_is_found_by_its_key(): void
    {
        $registry = new MetricRegistry;
        $metric = new FixedMetric('acme.retention');

        $registry->register($metric);

        $this->assertTrue($registry->has('acme.retention'));
        $this->assertSame($metric, $registry->get('acme.retention'));
    }

    public function test_a_key_is_registered_once_and_never_replaced(): void
    {
        $registry = new MetricRegistry;
        $first = new FixedMetric('acme.retention', '1');
        $registry->register($first);

        try {
            $registry->register(new FixedMetric('acme.retention', '2'));
            $this->fail('A second metric claimed a registered key.');
        } catch (DuplicateMetric $exception) {
            $this->assertStringContainsString('"acme.retention" is already registered', $exception->getMessage());
            $this->assertSame($first, $registry->get('acme.retention'));
        }
    }

    public function test_the_built_in_key_cannot_be_claimed_again(): void
    {
        $this->expectException(DuplicateMetric::class);

        $this->app->make(MetricRegistry::class)->register(new FixedMetric('member.volume'));
    }

    public function test_an_unknown_key_is_refused_rather_than_resolved_as_zero(): void
    {
        $this->expectException(UnknownMetric::class);
        $this->expectExceptionMessage('No metric is registered under "does.not.exist". Registered: member.volume.');

        $this->app->make(MetricEngine::class)->resolve('does.not.exist', new MetricContext(new Member));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Member.Volume'],
            'a class name' => ['App\\Metrics\\Retention'],
            'a space' => ['member volume'],
            'a leading dot' => ['.volume'],
            'over 100 characters' => [str_repeat('a', 101)],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function test_an_invalid_key_is_refused(string $key): void
    {
        $registry = new MetricRegistry;

        try {
            $registry->register(new FixedMetric($key));
            $this->fail("The key \"{$key}\" was accepted.");
        } catch (InvalidMetric $exception) {
            $this->assertStringContainsString('lowercase letters', $exception->getMessage());
            $this->assertSame([], $registry->keys());
        }
    }

    public function test_keys_are_listed_in_order(): void
    {
        $registry = new MetricRegistry;

        foreach (['zeta.one', 'alpha.two', 'mid.three'] as $key) {
            $registry->register(new FixedMetric($key));
        }

        $this->assertSame(['alpha.two', 'mid.three', 'zeta.one'], $registry->keys());
    }

    public function test_the_engine_resolves_whatever_the_registry_holds(): void
    {
        $registry = new MetricRegistry;
        $registry->register(new FixedMetric('acme.retention', '0.125'));

        $value = (new MetricEngine($registry))->resolve('acme.retention', new MetricContext(new Member));

        $this->assertSame('0.125', $value->value());
    }

    public function test_a_provider_registered_after_the_registry_was_built_still_extends_it(): void
    {
        $registry = $this->app->make(MetricRegistry::class);

        $this->app->register(ExampleMetricServiceProvider::class);

        $this->assertSame(['custom.example', 'member.volume'], $registry->keys());
    }
}
