<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning;

use Illuminate\Database\Connection;
use PandaBear\Mlm\Exceptions\InvalidMetricParameters;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;
use PandaBear\Mlm\Metrics\MetricRegistry;
use PandaBear\Mlm\Metrics\PlanConfigurableMetric;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;

/**
 * Judges a plan version's complete stored definition, as
 * `PlanVersionLifecycle::markValidated()` requires before a version may be
 * validated:
 *
 * - every component's key, driver key, name and parameters are well-formed,
 *   and its driver is registered;
 * - every rule is well-formed in the safe rule language;
 * - every metric a condition names is registered, is plan-configurable, and
 *   accepts the condition's parameters;
 * - every component's driver accepts the component, rules included.
 *
 * It reads and judges. It resolves no metric and evaluates no rule: no
 * member is involved. An empty definition is valid.
 */
final readonly class PlanDefinitionValidator
{
    public function __construct(
        private PlanComponentDriverRegistry $drivers,
        private MetricRegistry $metrics,
    ) {}

    /**
     * @throws InvalidPlanDefinition naming the version, component, rule and metric at fault
     */
    public function validate(PlanVersion $version): void
    {
        foreach ($this->definition($version) as $component) {
            $where = "component \"{$component->key}\"";

            if (! $this->drivers->has($component->driver)) {
                throw InvalidPlanDefinition::inVersion($version, $where, "no plan component driver is registered under \"{$component->driver}\".");
            }

            foreach ($component->rules as $rule) {
                foreach ($rule->definition->conditions() as $path => $condition) {
                    $this->validateMetric($version, "{$where}, rule \"{$rule->key}\", {$path}, metric \"{$condition->metric}\"", $condition->metric, $condition->parameters);
                }
            }

            try {
                $this->drivers->get($component->driver)->validate($component);
            } catch (InvalidPlanDefinition $exception) {
                throw InvalidPlanDefinition::inVersion($version, "{$where}, driver \"{$component->driver}\"", $exception->getMessage(), $exception);
            }
        }
    }

    /**
     * The version's stored definition as read-only data, in order — by
     * position, then id — with every rule parsed. Drivers and metrics are not
     * consulted: this is what is stored, not whether it is valid.
     *
     * @return list<PlanComponentDefinition>
     *
     * @throws InvalidPlanDefinition for stored data only a raw write could have produced
     */
    public function definition(PlanVersion $version): array
    {
        $db = $version->getConnection();
        $components = $db->table('mlm_plan_components')->where('plan_version_id', $version->getKey())->orderBy('position')->orderBy('id')->get();
        $rules = $this->rules($db, $components->pluck('id')->all());

        return $components->map(fn (object $row): PlanComponentDefinition => $this->component($version, $row, $rules[$row->id] ?? []))->values()->all();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function validateMetric(PlanVersion $version, string $where, string $key, array $parameters): void
    {
        if (! $this->metrics->has($key)) {
            throw InvalidPlanDefinition::inVersion($version, $where, 'no metric is registered under this key.');
        }

        $metric = $this->metrics->get($key);

        if (! $metric instanceof PlanConfigurableMetric) {
            throw InvalidPlanDefinition::inVersion($version, $where, sprintf(
                'the metric exists but is not plan-configurable: %s does not implement %s, so its parameters cannot be checked before it is resolved.',
                $metric::class,
                PlanConfigurableMetric::class,
            ));
        }

        try {
            $metric->validatePlanParameters($parameters);
        } catch (InvalidMetricParameters $exception) {
            throw InvalidPlanDefinition::inVersion($version, $where, $exception->getMessage(), $exception);
        }
    }

    /**
     * @param  list<object>  $rules
     */
    private function component(PlanVersion $version, object $row, array $rules): PlanComponentDefinition
    {
        $where = 'component '.json_encode((string) $row->key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $key = DefinitionInput::key('component key', $row->key);
            $driver = DefinitionInput::driver($row->driver);
            $name = DefinitionInput::name('component name', $row->name);
            $parameters = DefinitionInput::stored($row->parameters, 'component parameters');
        } catch (InvalidPlanDefinition $exception) {
            throw InvalidPlanDefinition::inVersion($version, $where, $exception->getMessage(), $exception);
        }

        return new PlanComponentDefinition($key, $driver, $name, $parameters, (int) $row->position, array_map(
            fn (object $rule): PlanRuleDefinition => $this->rule($version, "component \"{$key}\"", $rule),
            $rules,
        ), (string) $version->getKey(), $version->version);
    }

    private function rule(PlanVersion $version, string $component, object $row): PlanRuleDefinition
    {
        $where = "{$component}, rule ".json_encode((string) $row->key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            return new PlanRuleDefinition(
                DefinitionInput::key('rule key', $row->key),
                DefinitionInput::name('rule name', $row->name),
                (int) $row->position,
                RuleDefinition::fromJson($row->definition),
            );
        } catch (InvalidPlanDefinition|InvalidRuleDefinition $exception) {
            throw InvalidPlanDefinition::inVersion($version, $where, $exception->getMessage(), $exception);
        }
    }

    /**
     * Every rule of these components, grouped by component, in order.
     *
     * @param  list<string>  $componentIds
     * @return array<string, list<object>>
     */
    private function rules(Connection $db, array $componentIds): array
    {
        if ($componentIds === []) {
            return [];
        }

        return $db->table('mlm_plan_rules')
            ->whereIn('plan_component_id', $componentIds)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('plan_component_id')
            ->map(static fn ($rules): array => $rules->values()->all())
            ->all();
    }
}
