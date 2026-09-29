<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Closure;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Metrics\MetricRegistry;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Fixtures\FixedMetric;
use PandaBear\Mlm\Tests\Fixtures\ScoreMetric;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * A version is validated only if its whole definition is: every driver
 * registered and satisfied, every rule in the safe language, every metric
 * registered, plan-configurable and given parameters it accepts. Otherwise it
 * stays a draft, untouched. Nothing is resolved or evaluated.
 */
final class PlanDefinitionValidationTest extends DatabaseTestCase
{
    use BuildsPlanDefinitions;

    public function test_a_valid_definition_is_validated_with_its_driver_consulted(): void
    {
        $this->travelTo('2026-07-01 09:00:00');
        $version = $this->validDraft();
        $before = $this->storedDefinition($version);

        $validated = $this->lifecycle()->markValidated($version);

        $this->assertSame(PlanVersionStatus::Validated, $validated->status);
        $this->assertSame('2026-07-01 09:00:00', $validated->validated_at?->format('Y-m-d H:i:s'));
        $this->assertSame($before, $this->storedDefinition($validated));

        [$seen] = $this->criteriaDriver()->validated;
        $this->assertSame(['entry', 'test.criteria', 'Entry criteria', ['mode' => 'strict']], [$seen->key, $seen->driver, $seen->name, $seen->parameters]);
        $this->assertSame(['qualifies'], array_map(static fn ($rule): string => $rule->key, $seen->rules));
        $this->assertSame($this->qualifyingRule()->toArray(), $seen->rule('qualifies')?->definition->toArray());
    }

    public function test_an_empty_definition_is_valid(): void
    {
        $this->assertSame(PlanVersionStatus::Validated, $this->lifecycle()->markValidated($this->draft())->status);
    }

    public function test_every_built_in_metric_and_a_custom_configurable_metric_can_be_named(): void
    {
        $this->app->make(MetricRegistry::class)->register(new ScoreMetric);
        $version = $this->validDraft();
        $component = $version->components()->sole();

        $this->editor()->addRule($component, 'custom', 'Custom', RuleDefinition::any(
            MetricCondition::of('acme.score', ['window' => 30], 'in', '1', '7'),
            MetricCondition::of('member.volume', ['type' => 'retail'], '!=', '0'),
            MetricCondition::of('sponsor.network.volume', ['type' => 'sales'], '<', '10'),
            MetricCondition::of('placement.network.volume', ['type' => 'sales', 'max_depth' => 1], 'not_in', '5'),
        ));

        $this->assertSame(PlanVersionStatus::Validated, $this->lifecycle()->markValidated($version)->status);
    }

    /**
     * @return array<string, array{Closure(self, PlanVersion): void, string}>
     */
    public static function invalidDefinitions(): array
    {
        $rule = static fn (string $metric, array $parameters): RuleDefinition => RuleDefinition::all(MetricCondition::of($metric, $parameters, '>=', '1'));
        $addRule = static fn (self $test, PlanVersion $version, RuleDefinition $definition) => $test->editor()->addRule($version->components()->sole(), 'extra', 'Extra', $definition);

        return [
            'an unknown driver' => [
                static fn (self $test, PlanVersion $version) => $test->editor()->addComponent($version, 'ghost', 'acme.ghost', 'Ghost'),
                'component "ghost": no plan component driver is registered under "acme.ghost"',
            ],
            'a driver refusing its parameters' => [
                static fn (self $test, PlanVersion $version) => $test->editor()->updateComponent($version->components()->sole(), parameters: ['mode' => 'loose']),
                'component "entry", driver "test.criteria": Invalid plan definition parameter "mode": the criteria driver needs "strict" or "lenient".',
            ],
            'a driver refusing its rules' => [
                static fn (self $test, PlanVersion $version) => $test->editor()->removeRule($version->components()->sole()->rules()->sole()),
                'a strict criteria component needs at least one rule',
            ],
            'an unknown metric' => [
                static fn (self $test, PlanVersion $version) => $addRule($test, $version, $rule('does.not.exist', [])),
                'component "entry", rule "extra", root.children[0], metric "does.not.exist": no metric is registered under this key.',
            ],
            'a runtime-only metric' => [
                static function (self $test, PlanVersion $version) use ($addRule, $rule): void {
                    $test->app->make(MetricRegistry::class)->register(new FixedMetric('acme.runtime'));
                    $addRule($test, $version, $rule('acme.runtime', []));
                },
                'metric "acme.runtime": the metric exists but is not plan-configurable',
            ],
            'member.volume without a type' => [
                static fn (self $test, PlanVersion $version) => $addRule($test, $version, $rule('member.volume', [])),
                'metric "member.volume": The metric "member.volume" requires the "type" parameter.',
            ],
            'member.volume with an unknown parameter' => [
                static fn (self $test, PlanVersion $version) => $addRule($test, $version, $rule('member.volume', ['type' => 'sales', 'period' => 'monthly'])),
                'The metric "member.volume" does not accept "period"',
            ],
            'sponsor.network.volume with an invalid max_depth' => [
                static fn (self $test, PlanVersion $version) => $addRule($test, $version, $rule('sponsor.network.volume', ['type' => 'sales', 'max_depth' => 0])),
                'The metric "sponsor.network.volume" received an invalid "max_depth"',
            ],
            'placement.network.volume with an invalid type' => [
                static fn (self $test, PlanVersion $version) => $addRule($test, $version, $rule('placement.network.volume', ['type' => 'Sales'])),
                'The metric "placement.network.volume" received an invalid "type"',
            ],
            'binary.left.volume with an invalid max_depth' => [
                static fn (self $test, PlanVersion $version) => $addRule($test, $version, $rule('binary.left.volume', ['type' => 'sales', 'max_depth' => '3'])),
                'The metric "binary.left.volume" received an invalid "max_depth"',
            ],
            'binary.right.volume with an unknown parameter' => [
                static fn (self $test, PlanVersion $version) => $addRule($test, $version, $rule('binary.right.volume', ['type' => 'sales', 'carry' => true])),
                'The metric "binary.right.volume" does not accept "carry"',
            ],
            'a custom metric refusing its parameters' => [
                static function (self $test, PlanVersion $version) use ($addRule, $rule): void {
                    $test->app->make(MetricRegistry::class)->register(new ScoreMetric);
                    $addRule($test, $version, $rule('acme.score', ['window' => 400]));
                },
                'metric "acme.score": The metric "acme.score" received an invalid "window"',
            ],
            'a stored rule outside the language' => [
                static fn (self $test, PlanVersion $version) => DB::table('mlm_plan_rules')->update(['definition' => '{"type": "condition", "metric": "member.volume", "parameters": {}, "operator": ">", "operands": ["1"]}']),
                'component "entry", rule "qualifies": Invalid rule definition at root: the root is a group',
            ],
            'a stored rule that is not JSON' => [
                static fn (self $test, PlanVersion $version) => DB::table('mlm_plan_rules')->update(['definition' => '"eval(\'x\')"']),
                'rule "qualifies": Invalid rule definition at root: a node is an object',
            ],
            'stored parameters that are not an object' => [
                static fn (self $test, PlanVersion $version) => DB::table('mlm_plan_components')->update(['parameters' => '"strict"']),
                'component "entry": The stored component parameters cannot be read',
            ],
            'a stored driver that is a class name' => [
                static fn (self $test, PlanVersion $version) => DB::table('mlm_plan_components')->update(['driver' => 'App\\Drivers\\Criteria']),
                'is not a driver key',
            ],
            'a stored driver differing only in case' => [
                static fn (self $test, PlanVersion $version) => DB::table('mlm_plan_components')->update(['driver' => 'Test.Criteria']),
                'is not a driver key',
            ],
        ];
    }

    /**
     * @param  Closure(self, PlanVersion): void  $break
     */
    #[DataProvider('invalidDefinitions')]
    public function test_an_invalid_definition_leaves_the_version_a_draft(Closure $break, string $reason): void
    {
        $version = $this->validDraft();
        $break($this, $version);
        $before = $this->rows();

        try {
            $this->lifecycle()->markValidated($version);
            $this->fail('An invalid definition was validated.');
        } catch (InvalidPlanDefinition $exception) {
            $this->assertStringContainsString("Plan version [{$version->id}] (version 1) has an invalid definition: ", $exception->getMessage());
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        $stored = DB::table('mlm_plan_versions')->where('id', $version->id)->first();
        $this->assertSame('draft', $stored->status);
        $this->assertNull($stored->validated_at);
        $this->assertSame($before, $this->rows());
    }

    public function test_a_fixed_definition_is_then_validated(): void
    {
        $version = $this->validDraft();
        $ghost = $this->editor()->addComponent($version, 'ghost', 'acme.ghost', 'Ghost');

        try {
            $this->lifecycle()->markValidated($version);
            $this->fail('An unknown driver was accepted.');
        } catch (InvalidPlanDefinition) {
        }

        $this->editor()->removeComponent($ghost);

        $this->assertSame(PlanVersionStatus::Validated, $this->lifecycle()->markValidated($version)->status);
    }

    public function test_a_runtime_only_metric_still_resolves_through_the_engine(): void
    {
        $this->app->make(MetricRegistry::class)->register(new FixedMetric('acme.runtime', '4.5'));

        $value = $this->app->make(MetricEngine::class)->resolve('acme.runtime', new MetricContext(Member::factory()->create()));

        $this->assertSame('4.5', $value->value());
    }

    public function test_built_in_metrics_judge_plan_parameters_exactly_as_they_resolve(): void
    {
        $member = Member::factory()->create();
        $engine = $this->app->make(MetricEngine::class);
        $metrics = $this->app->make(MetricRegistry::class);

        $cases = [
            'member.volume' => [['type' => 'sales'], [], ['type' => 'sales', 'max_depth' => 1], ['type' => 5]],
            'sponsor.network.volume' => [['type' => 'sales', 'max_depth' => 2], ['type' => 'sales', 'max_depth' => '2'], ['type' => 'sales', 'levels' => 1]],
            'placement.network.volume' => [['type' => 'sales'], ['max_depth' => 1], ['type' => 'sales', 'max_depth' => null]],
            'binary.left.volume' => [['type' => 'sales', 'max_depth' => 3], ['type' => 'sales', 'max_depth' => 0], ['type' => 'Sales']],
            'binary.right.volume' => [['type' => 'sales'], ['type' => 'sales', 'max_depth' => 2.0], ['type' => 'sales', 'side' => 'right']],
        ];

        foreach ($cases as $key => $parameterSets) {
            foreach ($parameterSets as $parameters) {
                $runtime = $this->outcome(static fn () => $engine->resolve($key, new MetricContext($member, $parameters)));
                $plan = $this->outcome(static fn () => $metrics->get($key)->validatePlanParameters($parameters));

                $this->assertSame($runtime, $plan, "{$key} ".json_encode($parameters));
            }
        }
    }

    public function test_the_definition_is_read_in_order_without_consulting_drivers_or_metrics(): void
    {
        $version = $this->draft();
        $late = $this->editor()->addComponent($version, 'late', 'acme.unregistered', 'Late', ['b' => 1], 5);
        $this->editor()->addComponent($version, 'early', 'acme.unregistered', 'Early', position: 1);
        $this->editor()->addRule($late, 'second', 'Second', RuleDefinition::all(MetricCondition::of('does.not.exist', [], '>', '1')), 2);
        $this->editor()->addRule($late, 'first', 'First', $this->qualifyingRule(), 1);

        $definition = $this->validator()->definition($version);

        $this->assertSame(['early', 'late'], array_map(static fn ($component): string => $component->key, $definition));
        $this->assertSame(['first', 'second'], array_map(static fn ($rule): string => $rule->key, $definition[1]->rules));
        $this->assertSame(['b' => 1], $definition[1]->parameters);
        $this->assertSame(5, $definition[1]->position);
    }

    /**
     * Every stored component and rule row, exactly.
     *
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function rows(): array
    {
        return [
            DB::table('mlm_plan_components')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            DB::table('mlm_plan_rules')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        ];
    }

    /**
     * The message a call throws, or null when it succeeds.
     *
     * @param  Closure(): mixed  $call
     */
    private function outcome(Closure $call): ?string
    {
        try {
            $call();

            return null;
        } catch (Throwable $exception) {
            return $exception::class.': '.$exception->getMessage();
        }
    }
}
