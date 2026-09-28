<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Concerns;

use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Planning\DefinitionInput;
use PandaBear\Mlm\Planning\PlanComponentDriverRegistry;
use PandaBear\Mlm\Planning\PlanDefinitionCloner;
use PandaBear\Mlm\Planning\PlanDefinitionEditor;
use PandaBear\Mlm\Planning\PlanDefinitionValidator;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Planning\Rules\RuleGroup;
use PandaBear\Mlm\Tests\Fixtures\CriteriaDriver;

/**
 * Plan versions and definitions built the supported way — through the
 * lifecycle and the editor — and read back as plain data a failure message
 * can show.
 */
trait BuildsPlanDefinitions
{
    protected function editor(): PlanDefinitionEditor
    {
        return $this->app->make(PlanDefinitionEditor::class);
    }

    protected function lifecycle(): PlanVersionLifecycle
    {
        return $this->app->make(PlanVersionLifecycle::class);
    }

    protected function cloner(): PlanDefinitionCloner
    {
        return $this->app->make(PlanDefinitionCloner::class);
    }

    protected function validator(): PlanDefinitionValidator
    {
        return $this->app->make(PlanDefinitionValidator::class);
    }

    protected function criteriaDriver(): CriteriaDriver
    {
        $drivers = $this->app->make(PlanComponentDriverRegistry::class);

        if (! $drivers->has('test.criteria')) {
            $drivers->register(new CriteriaDriver);
        }

        $driver = $drivers->get('test.criteria');
        assert($driver instanceof CriteriaDriver);

        return $driver;
    }

    protected function draft(?Plan $plan = null): PlanVersion
    {
        return $this->lifecycle()->draft($plan ?? Plan::factory()->create());
    }

    /**
     * A rule over the three built-in metrics: nested, every operand form.
     */
    protected function qualifyingRule(): RuleDefinition
    {
        return RuleDefinition::all(
            MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100'),
            RuleGroup::any(
                MetricCondition::of('sponsor.network.volume', ['type' => 'sales', 'max_depth' => 3], '>=', '500'),
                MetricCondition::of('placement.network.volume', ['type' => 'sales', 'max_depth' => 2], 'between', '-25.5', '400'),
            ),
        );
    }

    /**
     * A draft with one strict criteria component holding the qualifying
     * rule: valid as it stands.
     */
    protected function validDraft(?Plan $plan = null): PlanVersion
    {
        $this->criteriaDriver();
        $version = $this->draft($plan);
        $component = $this->editor()->addComponent($version, 'entry', 'test.criteria', 'Entry criteria', ['mode' => 'strict']);
        $this->editor()->addRule($component, 'qualifies', 'Qualifies', $this->qualifyingRule());

        return $version;
    }

    /**
     * A rule with this definition in a validated version of `$plan`: the
     * rule as stored, ready to evaluate.
     */
    protected function validatedRule(RuleDefinition $definition, ?Plan $plan = null, string $key = 'rule'): PlanRule
    {
        $this->criteriaDriver();
        $version = $this->draft($plan);
        $component = $this->editor()->addComponent($version, 'entry', 'test.criteria', 'Entry criteria', ['mode' => 'lenient']);
        $rule = $this->editor()->addRule($component, $key, 'Rule', $definition);

        $this->lifecycle()->markValidated($version);

        return PlanRule::query()->findOrFail($rule->id);
    }

    /**
     * A version brought to `$status` through the lifecycle, never by writing
     * the status, with the valid definition. A superseded version is one a
     * newer version replaced.
     */
    protected function versionIn(PlanVersionStatus $status, ?Plan $plan = null): PlanVersion
    {
        $plan ??= Plan::factory()->create();
        $lifecycle = $this->lifecycle();

        return match ($status) {
            PlanVersionStatus::Draft => $this->validDraft($plan),
            PlanVersionStatus::Validated => $lifecycle->markValidated($this->validDraft($plan)),
            PlanVersionStatus::Published => $lifecycle->publish($this->versionIn(PlanVersionStatus::Validated, $plan)),
            PlanVersionStatus::Active => $lifecycle->activate($this->versionIn(PlanVersionStatus::Published, $plan)),
            PlanVersionStatus::Superseded => $this->superseded($plan),
            PlanVersionStatus::Archived => $lifecycle->archive($this->superseded($plan)),
        };
    }

    /**
     * Every component and rule of a version, as stored, keyed by position
     * order: what a definition looks like, without ids or timestamps.
     *
     * @return list<array<string, mixed>>
     */
    protected function storedDefinition(PlanVersion $version): array
    {
        return DB::connection($version->getConnectionName())->table('mlm_plan_components')
            ->where('plan_version_id', $version->id)
            ->orderBy('position')->orderBy('id')
            ->get()
            ->map(static fn (object $component): array => [
                'key' => $component->key,
                'driver' => $component->driver,
                'name' => $component->name,
                'parameters' => DefinitionInput::stored($component->parameters, 'component parameters'),
                'position' => (int) $component->position,
                'rules' => DB::connection($version->getConnectionName())->table('mlm_plan_rules')
                    ->where('plan_component_id', $component->id)
                    ->orderBy('position')->orderBy('id')
                    ->get()
                    ->map(static fn (object $rule): array => [
                        'key' => $rule->key,
                        'name' => $rule->name,
                        'definition' => RuleDefinition::fromJson($rule->definition)->toArray(),
                        'position' => (int) $rule->position,
                    ])->all(),
            ])->all();
    }

    private function superseded(Plan $plan): PlanVersion
    {
        $version = $this->versionIn(PlanVersionStatus::Active, $plan);
        $this->versionIn(PlanVersionStatus::Active, $plan);

        return $version->refresh();
    }
}
