<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Support\Facades\DB;
use LogicException;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Planning\PlanComponentDriverRegistry;
use PandaBear\Mlm\Planning\PlanDefinitionValidator;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Fixtures\CriteriaDriver;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A locked definition changes only by being copied into a new draft, which
 * is edited and validated in its turn. The copy is whole, atomic, and new.
 */
final class PlanDefinitionClonerTest extends DatabaseTestCase
{
    use BuildsPlanDefinitions;

    /**
     * @return array<string, array{PlanVersionStatus}>
     */
    public static function sources(): array
    {
        return [
            'validated' => [PlanVersionStatus::Validated],
            'published' => [PlanVersionStatus::Published],
            'active' => [PlanVersionStatus::Active],
            'draft' => [PlanVersionStatus::Draft],
        ];
    }

    #[DataProvider('sources')]
    public function test_a_clone_is_a_new_draft_with_the_whole_definition(PlanVersionStatus $status): void
    {
        $plan = Plan::factory()->create();
        $source = $this->richVersion($plan, $status);
        $before = [$this->storedDefinition($source), $this->storedVersion($source)];
        $sourceIds = $this->ids($source);

        $clone = $this->cloner()->cloneToNewDraft($source);

        $this->assertSame(PlanVersionStatus::Draft, $clone->status);
        $this->assertSame($source->version + 1, $clone->version);
        $this->assertTrue($clone->plan->is($plan));
        $this->assertSame([null, null, null, null, null], [$clone->validated_at, $clone->published_at, $clone->activated_at, $clone->superseded_at, $clone->archived_at]);

        $this->assertSame($this->storedDefinition($source), $this->storedDefinition($clone));
        $this->assertSame([], array_intersect($sourceIds, $this->ids($clone)));
        $this->assertCount(count($sourceIds), $this->ids($clone));

        $this->assertSame($before, [$this->storedDefinition($source), $this->storedVersion($source->fresh())]);
    }

    public function test_the_clone_is_edited_and_validated_independently_of_its_source(): void
    {
        $source = $this->lifecycle()->markValidated($this->validDraft());
        $before = $this->storedDefinition($source);
        $clone = $this->cloner()->cloneToNewDraft($source);

        $component = $clone->components()->sole();
        $this->editor()->updateComponent($component, parameters: ['mode' => 'lenient']);
        $this->editor()->addRule($component, 'second', 'Second', RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'retail'], '>', '0')));

        $this->assertSame(PlanVersionStatus::Validated, $this->lifecycle()->markValidated($clone)->status);
        $this->assertSame($before, $this->storedDefinition($source));
        $this->assertCount(2, $this->storedDefinition($clone)[0]['rules']);
    }

    public function test_a_failed_copy_leaves_no_draft_behind(): void
    {
        $source = $this->richVersion(Plan::factory()->create(), PlanVersionStatus::Validated);
        $components = DB::table('mlm_plan_components')->count();
        $rules = DB::table('mlm_plan_rules')->count();

        // Only a raw write can store this: the last rule of the last
        // component is outside the language, so the copy fails part-way.
        $last = DB::table('mlm_plan_rules')->orderByDesc('position')->orderByDesc('id')->first();
        DB::table('mlm_plan_rules')->where('id', $last->id)->update(['definition' => '{"type": "group", "match": "all", "children": [], "sql": "DROP TABLE x"}']);

        try {
            $this->cloner()->cloneToNewDraft($source);
            $this->fail('A source outside the rule language was copied.');
        } catch (InvalidRuleDefinition $exception) {
            $this->assertStringContainsString('unknown field "sql"', $exception->getMessage());
        }

        $this->assertSame(1, PlanVersion::query()->where('plan_id', $source->plan_id)->count());
        $this->assertSame([$components, $rules], [DB::table('mlm_plan_components')->count(), DB::table('mlm_plan_rules')->count()]);
    }

    public function test_unreadable_parameters_stop_the_copy_too(): void
    {
        $source = $this->lifecycle()->markValidated($this->validDraft());
        DB::table('mlm_plan_components')->update(['parameters' => '[1, 2]']);

        $this->expectException(InvalidPlanDefinition::class);
        $this->expectExceptionMessage('parameters of component "entry" cannot be read');

        try {
            $this->cloner()->cloneToNewDraft($source);
        } finally {
            $this->assertSame(1, PlanVersion::query()->where('plan_id', $source->plan_id)->count());
        }
    }

    public function test_a_definition_is_copied_without_its_driver_but_not_validated_without_it(): void
    {
        $source = $this->lifecycle()->markValidated($this->validDraft());

        // The application no longer registers the driver: a fresh registry.
        $this->app->forgetInstance(PlanComponentDriverRegistry::class);
        $this->app->forgetInstance(PlanDefinitionValidator::class);

        $clone = $this->cloner()->cloneToNewDraft($source);
        $this->assertSame($this->storedDefinition($source), $this->storedDefinition($clone));

        try {
            $this->lifecycle()->markValidated($clone);
            $this->fail('A definition naming a missing driver was validated.');
        } catch (InvalidPlanDefinition $exception) {
            $this->assertStringContainsString('no plan component driver is registered under "test.criteria"', $exception->getMessage());
        }

        $this->assertSame('draft', DB::table('mlm_plan_versions')->where('id', $clone->id)->value('status'));

        $this->app->make(PlanComponentDriverRegistry::class)->register(new CriteriaDriver);

        $this->assertSame(PlanVersionStatus::Validated, $this->lifecycle()->markValidated($clone)->status);
    }

    public function test_an_empty_definition_clones_to_an_empty_draft(): void
    {
        $source = $this->lifecycle()->markValidated($this->draft());

        $clone = $this->cloner()->cloneToNewDraft($source);

        $this->assertSame(2, $clone->version);
        $this->assertSame([], $this->storedDefinition($clone));
    }

    /**
     * Two components, three rules, nested trees, parameters and positions —
     * brought to `$status`.
     */
    private function richVersion(Plan $plan, PlanVersionStatus $status): PlanVersion
    {
        $version = $this->validDraft($plan);
        $editor = $this->editor();

        $bonus = $editor->addComponent($version, 'bonus', 'test.criteria', 'Bonus tiers', [
            'mode' => 'lenient',
            'tiers' => [['from' => '0', 'rate' => '0.05'], ['from' => '1000', 'rate' => '0.1']],
        ], 7);
        $editor->addRule($bonus, 'tier-two', 'Tier two', RuleDefinition::any(
            MetricCondition::of('sponsor.network.volume', ['type' => 'sales', 'max_depth' => 1], 'between', '1000', '5000'),
            MetricCondition::of('member.volume', ['type' => 'retail'], 'in', '1', '2', '3'),
        ), 4);
        $editor->addRule($bonus, 'tier-one', 'Tier one', $this->qualifyingRule(), 2);

        if ($status === PlanVersionStatus::Draft) {
            return $version;
        }

        $version = $this->lifecycle()->markValidated($version);

        return match ($status) {
            PlanVersionStatus::Validated => $version,
            PlanVersionStatus::Published => $this->lifecycle()->publish($version),
            PlanVersionStatus::Active => $this->lifecycle()->activate($this->lifecycle()->publish($version)),
            default => throw new LogicException("No rich version in {$status->value}."),
        };
    }

    /**
     * @return list<string> the ids of every component and rule of the version
     */
    private function ids(PlanVersion $version): array
    {
        $components = DB::table('mlm_plan_components')->where('plan_version_id', $version->id)->pluck('id')->all();

        return [...$components, ...DB::table('mlm_plan_rules')->whereIn('plan_component_id', $components)->pluck('id')->all()];
    }

    /**
     * @return array<string, mixed>
     */
    private function storedVersion(PlanVersion $version): array
    {
        return (array) DB::table('mlm_plan_versions')->where('id', $version->id)->first();
    }
}
