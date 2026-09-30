<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Panel;

use Illuminate\Testing\TestResponse;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Resources\Plans\PlanResource;
use PandaBear\Mlm\Panel\Resources\Plans\PlanVersionComponentsRelation;
use PandaBear\Mlm\Panel\Resources\Plans\PlanVersionResource;
use PandaBear\Mlm\Planning\PlanDefinitionCloner;
use PandaBear\Mlm\Planning\PlanDefinitionEditor;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Program\ProgramManager;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaPanel\Tables\TableSchema;

/**
 * The Plan Builder (ADR-031): a plan, its versions and their definitions,
 * built only through the planning services — registered drivers,
 * strategies, metrics and operators only, edits only while a draft, and
 * every lifecycle step the lifecycle's own.
 */
final class PlanBuilderPanelTest extends PanelTestCase
{
    use BuildsLedgers;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->program = $this->app->make(ProgramManager::class)->create('MAIN', 'Main');
        $this->systemAccounts()->openSystemAccount($this->program, 'IDR', 'commission.payable');
        $this->grant(MlmPermission::PLANS_VIEW, MlmPermission::PLANS_OPERATE);
    }

    public function test_a_plan_is_built_validated_published_and_activated_through_the_planning_services(): void
    {
        $resolved = $this->spyOn([PlanDefinitionEditor::class, PlanVersionLifecycle::class, PlanDefinitionCloner::class, ProgramManager::class]);

        $this->submitTable('mlm-plans', 'new-plan', ['program_id' => $this->program->id, 'code' => 'COMP', 'name' => 'Compensation'])->assertSessionHas('success', 'Plan created.');
        $plan = Plan::query()->sole();
        $this->assertSame([$this->program->id, 0], [$plan->program_id, $plan->versions()->count()]);

        $this->runRecord('mlm-plans', 'new-draft', $plan)->assertSessionHas('success', 'Draft version created.');
        $draft = $plan->versions()->sole();
        $this->assertSame([1, PlanVersionStatus::Draft], [$draft->version, $draft->status]);

        $this->addComponent($draft, ['key' => 'direct', 'name' => 'Direct', 'strategy' => 'direct-sponsor.fixed', 'volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '1', 'amount' => '10'])
            ->assertSessionHas('success', 'Component added.');
        $this->addComponent($draft, ['key' => 'unilevel', 'name' => 'Unilevel', 'strategy' => 'unilevel.fixed', 'volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '1', 'amount' => '999', 'levels' => [['depth' => '1', 'value' => '5'], ['depth' => '2', 'value' => '2']]])
            ->assertSessionHas('success');
        $this->addComponent($draft, ['key' => 'ranks', 'name' => 'Ranks', 'driver' => 'rank.ladder', 'parameters_json' => '{}', 'position' => '0'])->assertSessionHas('success');

        // Execution order is the editor's: position, then id.
        $this->assertSame(['ranks', 'direct', 'unilevel'], $draft->components()->pluck('key')->all());

        $direct = PlanComponent::query()->where('key', 'direct')->sole();
        $this->assertSame($this->sorted([
            'strategy' => 'direct-sponsor.fixed',
            'currency' => 'IDR',
            'source_account' => 'commission.payable',
            'parameters' => ['volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '1', 'amount' => '10'],
        ]), $this->sorted($direct->parameters));

        // Only the strategy's own fields reach the definition: unilevel has
        // no single `amount`, and its levels award `amount` per depth.
        $this->assertSame(
            ['levels' => [['amount' => '5', 'depth' => 1], ['amount' => '2', 'depth' => 2]], 'minimum_quantity' => '1', 'source_type' => 'order', 'volume_type' => 'sales'],
            $this->sorted(PlanComponent::query()->where('key', 'unilevel')->sole()->parameters['parameters']),
        );

        $this->submitRelation('mlm-plan-versions', $draft, 'components', 'change-component', [
            'name' => 'Direct bonus', 'strategy' => 'direct-sponsor.fixed', 'currency' => 'IDR', 'source_account' => 'commission.payable',
            'volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '1', 'amount' => '12',
        ], $direct)->assertSessionHas('success', 'Component changed.');
        $this->assertSame(['Direct bonus', '12'], [$direct->refresh()->name, $direct->parameters['parameters']['amount']]);

        $ranks = PlanComponent::query()->where('key', 'ranks')->sole();
        $this->submitRelation('mlm-plan-versions', $draft, 'components', 'add-rule', [
            'key' => 'bronze', 'name' => 'Bronze', 'match' => 'all',
            'conditions' => [['metric' => 'sponsor.network.volume', 'parameters' => ['type' => 'sales', 'max_depth' => '3'], 'operator' => 'between', 'operands' => ['100', '500']]],
        ], $ranks)->assertSessionHas('success', 'Rule added.');

        $rule = PlanRule::query()->sole();
        $this->assertSame(
            ['type' => 'group', 'match' => 'all', 'children' => [['type' => 'condition', 'metric' => 'sponsor.network.volume', 'parameters' => ['max_depth' => 3, 'type' => 'sales'], 'operator' => 'between', 'operands' => ['100', '500']]]],
            $rule->definition->toArray(),
        );

        $this->submitRelation('mlm-plan-versions', $draft, 'rules', 'change-rule', [
            'name' => 'Bronze rank', 'match' => 'any',
            'conditions' => [['metric' => 'member.volume', 'parameters' => ['type' => 'sales'], 'operator' => '>=', 'operands' => ['100']]],
        ], $rule)->assertSessionHas('success', 'Rule changed.');
        $this->assertSame(['Bronze rank', 'any', 'member.volume'], [$rule->refresh()->name, $rule->definition->toArray()['match'], $rule->definition->toArray()['children'][0]['metric']]);

        $unilevel = PlanComponent::query()->where('key', 'unilevel')->sole();
        $this->runRelation('mlm-plan-versions', $draft, 'components', 'remove-component', $unilevel)->assertSessionHas('success', 'Component removed.');
        $this->assertSame(['ranks', 'direct'], $draft->components()->pluck('key')->all());

        // The review shows the whole definition, as the validator reads it.
        $review = $this->withHeaders(['X-Inertia' => 'true'])->get("/mlm/mlm-plan-versions/{$draft->id}")->assertOk();
        $this->assertStringContainsString('direct-sponsor.fixed', (string) $review->getContent());

        $this->runRecord('mlm-plan-versions', 'validate-version', $draft)->assertSessionHas('success', 'Version validated.');
        $this->assertSame(PlanVersionStatus::Validated, $draft->refresh()->status);

        // Validated is final for the definition: the builder is gone, and a
        // request that asks anyway is refused by the editor.
        $this->assertSame([], PlanVersionComponentsRelation::table(TableSchema::make(), $draft)->getHeaderActions()[0]->toArray() === null ? [] : ['offered']);
        $this->addComponent($draft, ['key' => 'late', 'name' => 'Late', 'strategy' => 'direct-sponsor.fixed', 'amount' => '1'])
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Add component was refused:'));
        $this->assertSame(2, $draft->components()->count());

        $this->runRecord('mlm-plan-versions', 'publish-version', $draft)->assertSessionHas('success', 'Version published.');
        $this->runRecord('mlm-plan-versions', 'activate-version', $draft)->assertSessionHas('success', 'Version activated.');
        $this->assertSame(PlanVersionStatus::Active, $draft->refresh()->status);

        // A change is a new draft, cloned — definition only.
        $this->runRecord('mlm-plan-versions', 'clone-to-draft', $draft)->assertSessionHas('success');
        $next = $plan->versions()->where('version', 2)->sole();
        $this->assertSame([PlanVersionStatus::Draft, ['ranks', 'direct']], [$next->status, $next->components()->pluck('key')->all()]);

        foreach (['validate-version', 'publish-version', 'activate-version'] as $step) {
            $this->runRecord('mlm-plan-versions', $step, $next)->assertSessionHas('success');
        }

        // One active version: activating the next superseded the first.
        $this->assertSame([PlanVersionStatus::Superseded, PlanVersionStatus::Active], [$draft->refresh()->status, $next->refresh()->status]);
        $this->assertSame(1, $plan->versions()->where('status', PlanVersionStatus::Active->value)->count());

        $this->runRecord('mlm-plan-versions', 'archive-version', $draft)->assertSessionHas('success', 'Version archived.');
        $this->assertSame(PlanVersionStatus::Archived, $draft->refresh()->status);

        // Every write went through a planning service.
        $this->assertGreaterThan(0, $resolved[PlanDefinitionEditor::class]);
        $this->assertGreaterThan(0, $resolved[PlanVersionLifecycle::class]);
        $this->assertGreaterThan(0, $resolved[PlanDefinitionCloner::class]);
        $this->assertSame(1, $resolved[ProgramManager::class]);
    }

    public function test_a_version_is_cloned_from_the_plan_into_a_new_draft(): void
    {
        $plan = $this->app->make(ProgramManager::class)->addPlan($this->program, 'COMP', 'Compensation');
        $first = $this->app->make(PlanVersionLifecycle::class)->draft($plan);
        $this->app->make(PlanDefinitionEditor::class)->addComponent($first, 'ranks', 'rank.ladder', 'Ranks');

        $this->submitRecord('mlm-plans', 'clone-version', $plan, ['version_id' => $first->id])->assertSessionHas('success');

        $clone = $plan->versions()->where('version', 2)->sole();
        $this->assertSame([PlanVersionStatus::Draft, ['ranks']], [$clone->status, $clone->components()->pluck('key')->all()]);
    }

    public function test_only_registered_drivers_strategies_metrics_and_operators_are_accepted(): void
    {
        $draft = $this->draftVersion();

        $this->addComponent($draft, ['key' => 'evil', 'name' => 'Evil', 'driver' => 'App\\Evil\\Driver', 'parameters_json' => '{}'])->assertSessionHasErrors('driver');
        $this->addComponent($draft, ['key' => 'evil', 'name' => 'Evil', 'strategy' => 'app.unregistered', 'amount' => '1'])->assertSessionHasErrors('strategy');
        $this->addComponent($draft, ['key' => 'code', 'name' => 'Code', 'driver' => 'rank.ladder', 'parameters_json' => '"system(\'id\')"'])->assertSessionHasErrors('parameters_json');
        $this->assertSame(0, $draft->components()->count());

        $ranks = $this->app->make(PlanDefinitionEditor::class)->addComponent($draft, 'ranks', 'rank.ladder', 'Ranks');

        foreach ([
            ['metric' => 'App\\Metric', 'operator' => '>=', 'operands' => ['1']],
            ['metric' => 'member.volume', 'operator' => '=', 'operands' => ['1']],
            ['metric' => 'member.volume', 'operator' => '>=', 'operands' => ['{{ 1 + 1 }}']],
        ] as $condition) {
            $this->submitRelation('mlm-plan-versions', $draft, 'components', 'add-rule', [
                'key' => 'bad', 'name' => 'Bad', 'match' => 'all', 'conditions' => [['parameters' => ['type' => 'sales'], ...$condition]],
            ], $ranks)->assertSessionMissing('success');
        }

        $this->assertSame(0, PlanRule::query()->count());
    }

    public function test_an_invalid_definition_is_refused_at_validation_and_stays_a_draft(): void
    {
        $draft = $this->draftVersion();
        $this->addComponent($draft, ['key' => 'direct', 'name' => 'Direct', 'strategy' => 'direct-sponsor.fixed', 'volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '1'])
            ->assertSessionHas('success');

        $this->runRecord('mlm-plan-versions', 'validate-version', $draft)
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Validate was refused:') && str_contains($message, 'amount'));

        $this->assertSame(PlanVersionStatus::Draft, $draft->refresh()->status);
    }

    public function test_viewing_plans_is_not_building_them(): void
    {
        $draft = $this->draftVersion();
        $this->grant(MlmPermission::PLANS_VIEW, MlmPermission::PROGRAMS_OPERATE, MlmPermission::PERIODS_OPERATE, MlmPermission::NETWORK_OPERATE);

        $this->addComponent($draft, ['key' => 'direct', 'name' => 'Direct', 'strategy' => 'direct-sponsor.fixed', 'amount' => '1'])->assertForbidden();
        $this->runRecord('mlm-plans', 'new-draft', $draft->plan)->assertForbidden();
        $this->runRecord('mlm-plan-versions', 'validate-version', $draft)->assertForbidden();
        $this->submitTable('mlm-plans', 'new-plan', ['program_id' => $this->program->id, 'code' => 'X', 'name' => 'X'])->assertForbidden();

        panelInfolistActions(PlanVersionResource::class)->assertHidden('validate-version', $draft);
        panelTableActions(PlanResource::class)->assertCanNotRun('new-plan');
        $this->assertSame(0, $draft->components()->count());
        $this->assertSame(PlanVersionStatus::Draft, $draft->refresh()->status);
    }

    private function draftVersion(): PlanVersion
    {
        return $this->app->make(PlanVersionLifecycle::class)->draft($this->app->make(ProgramManager::class)->addPlan($this->program, 'COMP', 'Compensation'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addComponent(PlanVersion $version, array $data): TestResponse
    {
        return $this->submitRelation('mlm-plan-versions', $version, 'components', 'add-component', [
            'driver' => 'commission.strategy',
            'currency' => 'IDR',
            'source_account' => 'commission.payable',
            ...$data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return array_map(fn (mixed $value): mixed => is_array($value) && ! array_is_list($value) ? $this->sorted($value) : $value, $values);
    }
}
