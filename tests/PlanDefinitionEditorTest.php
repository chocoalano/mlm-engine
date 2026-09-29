<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;
use PandaBear\Mlm\Exceptions\PlanVersionNotMutable;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * `PlanDefinitionEditor` is the one way a definition changes, and it changes
 * drafts only — judged from the stored, locked version, never from an
 * instance in memory.
 */
final class PlanDefinitionEditorTest extends DatabaseTestCase
{
    use BuildsPlanDefinitions;

    public function test_components_are_added_in_order_with_their_parameters(): void
    {
        $version = $this->draft();

        $first = $this->editor()->addComponent($version, 'entry', 'test.criteria', 'Entry criteria', ['mode' => 'strict', 'limits' => ['b' => 2, 'a' => 1]]);
        $second = $this->editor()->addComponent($version, 'bonus', 'acme.bonus', 'Bonus');

        $this->assertTrue($first->planVersion->is($version));
        $this->assertSame(['entry', 'bonus'], $version->components()->pluck('key')->all());
        $this->assertSame([1, 2], $version->components()->pluck('position')->all());
        $this->assertSame(['limits' => ['a' => 1, 'b' => 2], 'mode' => 'strict'], $first->parameters);
        $this->assertSame([], $second->parameters);
        $this->assertSame('{}', DB::table('mlm_plan_components')->where('id', $second->id)->value('parameters'));
    }

    public function test_components_and_rules_read_by_position_then_id(): void
    {
        $version = $this->draft();
        $late = $this->editor()->addComponent($version, 'late', 'acme.x', 'Late', position: 9);
        $tieA = $this->editor()->addComponent($version, 'tie-a', 'acme.x', 'Tie A', position: 3);
        $tieB = $this->editor()->addComponent($version, 'tie-b', 'acme.x', 'Tie B', position: 3);
        $first = $this->editor()->addComponent($version, 'first', 'acme.x', 'First', position: 0);

        $ties = [$tieA->id, $tieB->id];
        sort($ties);

        $this->assertSame([$first->id, ...$ties, $late->id], $version->components()->pluck('id')->all());
        // After the highest position when none is given.
        $this->assertSame(10, $this->editor()->addComponent($version, 'next', 'acme.x', 'Next')->position);

        $a = $this->editor()->addRule($first, 'a', 'A', $this->qualifyingRule(), position: 5);
        $b = $this->editor()->addRule($first, 'b', 'B', $this->qualifyingRule(), position: 1);
        $c = $this->editor()->addRule($first, 'c', 'C', $this->qualifyingRule());

        $this->assertSame([$b->id, $a->id, $c->id], $first->rules()->pluck('id')->all());
        $this->assertSame(6, $c->position);
    }

    public function test_a_component_is_updated_and_keeps_its_key(): void
    {
        $version = $this->draft();
        $component = $this->editor()->addComponent($version, 'entry', 'test.criteria', 'Entry', ['mode' => 'lenient'], 2);

        $updated = $this->editor()->updateComponent($component, name: 'Entry criteria', driver: 'acme.other', parameters: ['mode' => 'strict'], position: 7);

        $this->assertSame(['entry', 'acme.other', 'Entry criteria', ['mode' => 'strict'], 7], [$updated->key, $updated->driver, $updated->name, $updated->parameters, $updated->position]);

        $untouched = $this->editor()->updateComponent($updated, name: 'Renamed');
        $this->assertSame(['Renamed', 'acme.other', ['mode' => 'strict'], 7], [$untouched->name, $untouched->driver, $untouched->parameters, $untouched->position]);

        $this->assertSame([], $this->editor()->updateComponent($untouched, parameters: [])->parameters);
    }

    public function test_a_rule_is_added_updated_and_removed(): void
    {
        $component = $this->editor()->addComponent($this->draft(), 'entry', 'test.criteria', 'Entry');

        $rule = $this->editor()->addRule($component, 'qualifies', 'Qualifies', $this->qualifyingRule());
        $this->assertSame($this->qualifyingRule()->toArray(), $rule->definition->toArray());
        $this->assertTrue($rule->component->is($component));

        $simpler = RuleDefinition::any(MetricCondition::of('member.volume', ['type' => 'sales'], '>', '0'));
        $updated = $this->editor()->updateRule($rule, name: 'Any sale', definition: $simpler, position: 4);

        $this->assertSame(['qualifies', 'Any sale', 4], [$updated->key, $updated->name, $updated->position]);
        $this->assertSame($simpler->toArray(), $updated->definition->toArray());

        $this->editor()->removeRule($updated);

        $this->assertSame(0, $component->rules()->count());
    }

    public function test_removing_a_component_removes_its_rules(): void
    {
        $version = $this->draft();
        $component = $this->editor()->addComponent($version, 'entry', 'test.criteria', 'Entry');
        $this->editor()->addRule($component, 'one', 'One', $this->qualifyingRule());
        $this->editor()->addRule($component, 'two', 'Two', $this->qualifyingRule());
        $kept = $this->editor()->addComponent($version, 'kept', 'test.criteria', 'Kept');
        $this->editor()->addRule($kept, 'one', 'One', $this->qualifyingRule());

        $this->editor()->removeComponent($component);

        $this->assertSame(['kept'], $version->components()->pluck('key')->all());
        $this->assertSame(1, DB::table('mlm_plan_rules')->count());
    }

    public function test_a_component_key_is_used_once_per_version(): void
    {
        $version = $this->draft();
        $this->editor()->addComponent($version, 'entry', 'test.criteria', 'Entry');

        // Another version of the same plan may use it.
        $this->editor()->addComponent($this->draft($version->plan), 'entry', 'test.criteria', 'Entry');

        $this->expectException(InvalidPlanDefinition::class);
        $this->expectExceptionMessage('already has a component "entry"');

        $this->editor()->addComponent($version, 'entry', 'acme.other', 'Entry again');
    }

    public function test_a_rule_key_is_used_once_per_component(): void
    {
        $version = $this->draft();
        $entry = $this->editor()->addComponent($version, 'entry', 'test.criteria', 'Entry');
        $bonus = $this->editor()->addComponent($version, 'bonus', 'test.criteria', 'Bonus');
        $this->editor()->addRule($entry, 'qualifies', 'Qualifies', $this->qualifyingRule());
        $this->editor()->addRule($bonus, 'qualifies', 'Qualifies', $this->qualifyingRule());

        $this->expectException(InvalidPlanDefinition::class);
        $this->expectExceptionMessage('Component "entry" already has a rule "qualifies"');

        $this->editor()->addRule($entry, 'qualifies', 'Again', $this->qualifyingRule());
    }

    public function test_a_stale_draft_instance_cannot_change_a_validated_version(): void
    {
        $this->criteriaDriver();
        $stale = $this->draft();
        $this->lifecycle()->markValidated(PlanVersion::query()->findOrFail($stale->id));

        $this->assertSame(PlanVersionStatus::Draft, $stale->status);
        $this->expectException(PlanVersionNotMutable::class);

        $this->editor()->addComponent($stale, 'late', 'test.criteria', 'Too late');
    }

    public function test_a_stale_instance_of_a_removed_component_cannot_be_changed(): void
    {
        $component = $this->editor()->addComponent($this->draft(), 'entry', 'test.criteria', 'Entry');
        $this->editor()->removeComponent(PlanComponent::query()->findOrFail($component->id));

        $this->expectException(ModelNotFoundException::class);

        $this->editor()->updateComponent($component, name: 'Ghost');
    }

    /**
     * @return array<string, array{PlanVersionStatus}>
     */
    public static function lockedStatuses(): array
    {
        return array_combine(
            ['validated', 'published', 'active', 'superseded', 'archived'],
            [[PlanVersionStatus::Validated], [PlanVersionStatus::Published], [PlanVersionStatus::Active], [PlanVersionStatus::Superseded], [PlanVersionStatus::Archived]],
        );
    }

    #[DataProvider('lockedStatuses')]
    public function test_no_part_of_a_locked_definition_changes(PlanVersionStatus $status): void
    {
        $version = $this->versionIn($status);
        $this->assertSame($status, $version->status);

        $component = $version->components()->sole();
        $rule = $component->rules()->sole();
        $before = $this->storedDefinition($version);

        $changes = [
            'add a component' => fn () => $this->editor()->addComponent($version, 'more', 'test.criteria', 'More'),
            'update a component' => fn () => $this->editor()->updateComponent($component, name: 'Changed'),
            'remove a component' => fn () => $this->editor()->removeComponent($component),
            'add a rule' => fn () => $this->editor()->addRule($component, 'more', 'More', $this->qualifyingRule()),
            'update a rule' => fn () => $this->editor()->updateRule($rule, definition: RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>', '0'))),
            'remove a rule' => fn () => $this->editor()->removeRule($rule),
        ];

        foreach ($changes as $change => $attempt) {
            try {
                $attempt();
                $this->fail("A {$status->value} version let the editor {$change}.");
            } catch (PlanVersionNotMutable $exception) {
                $this->assertStringContainsString("is [{$status->value}] and locked", $exception->getMessage());
            }
        }

        $this->assertSame($before, $this->storedDefinition($version));
    }

    public function test_the_models_cannot_write_a_definition(): void
    {
        $version = $this->draft();
        $component = $this->editor()->addComponent($version, 'entry', 'test.criteria', 'Entry');
        $rule = $this->editor()->addRule($component, 'qualifies', 'Qualifies', $this->qualifyingRule());

        $attempts = [
            'create a component' => static function () use ($version): void {
                $component = new PlanComponent;
                $component->forceFill(['plan_version_id' => $version->id, 'key' => 'raw', 'driver' => 'x', 'name' => 'Raw', 'parameters' => '{}', 'position' => 1]);
                $component->save();
            },
            'update a component' => static fn () => $component->forceFill(['name' => 'Changed'])->save(),
            'delete a component' => static fn () => $component->delete(),
            'create a rule' => static function () use ($component): void {
                $rule = new PlanRule;
                $rule->forceFill(['plan_component_id' => $component->id, 'key' => 'raw', 'name' => 'Raw', 'definition' => '{}', 'position' => 1]);
                $rule->save();
            },
            'update a rule' => static fn () => $rule->forceFill(['position' => 99])->save(),
            'delete a rule' => static fn () => $rule->delete(),
        ];

        foreach ($attempts as $attempt => $write) {
            try {
                $write();
                $this->fail("The model let a caller {$attempt}.");
            } catch (InvalidPlanDefinition $exception) {
                $this->assertStringContainsString('read-only through Eloquent', $exception->getMessage());
            }
        }

        $this->assertSame(1, DB::table('mlm_plan_components')->count());
        $this->assertSame(1, DB::table('mlm_plan_rules')->count());
    }

    public function test_nothing_is_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);

        new PlanComponent(['plan_version_id' => 'another-version']);
    }

    public function test_deleting_a_draft_removes_its_whole_definition(): void
    {
        $version = $this->validDraft();
        $kept = $this->validDraft();

        $version->delete();

        $this->assertSame(1, DB::table('mlm_plan_components')->count());
        $this->assertSame(1, DB::table('mlm_plan_rules')->count());
        $this->assertSame(1, $kept->components()->count());
    }

    public function test_parameters_and_rules_round_trip_as_plain_data(): void
    {
        $parameters = [
            'mode' => 'strict',
            'tiers' => [['from' => '0', 'rate' => '0.05'], ['from' => '1000', 'rate' => '0.1']],
            'flags' => ['active' => true, 'note' => null],
            'count' => 3,
            'ratio' => 0.25,
            'label' => 'Été — 夏 🌞',
            'query' => 'SELECT * FROM users; phpinfo(); ${x} App\\Payout',
            'empty_list' => [],
        ];
        $component = $this->editor()->addComponent($this->draft(), 'entry', 'test.criteria', 'Entry', $parameters);
        $rule = $this->editor()->addRule($component, 'qualifies', 'Qualifies', $this->qualifyingRule());

        $read = PlanComponent::query()->findOrFail($component->id);

        $this->assertEquals($parameters, $read->parameters);
        $this->assertSame(array_keys($read->parameters), ['count', 'empty_list', 'flags', 'label', 'mode', 'query', 'ratio', 'tiers']);
        $this->assertSame($this->qualifyingRule()->toArray(), PlanRule::query()->findOrFail($rule->id)->definition->toArray());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedKeys(): array
    {
        return [
            'empty' => ['', 'is not a key'],
            'uppercase' => ['Entry', 'is not a key'],
            'surrounding space' => [' entry', 'is not a key'],
            'a class name' => ['App\\Plans\\Entry', 'is not a key'],
            'SQL' => ['entry; DROP TABLE x', 'is not a key'],
            'interpolation' => ['${key}', 'is not a key'],
            'too long' => [str_repeat('k', 65), 'is not a key'],
            'a trailing newline' => ["entry\n", 'is not a key'],
        ];
    }

    #[DataProvider('refusedKeys')]
    public function test_component_and_rule_keys_are_machine_keys(string $key, string $reason): void
    {
        $version = $this->draft();
        $component = $this->editor()->addComponent($version, 'entry', 'test.criteria', 'Entry');

        foreach ([
            fn () => $this->editor()->addComponent($version, $key, 'test.criteria', 'Name'),
            fn () => $this->editor()->addRule($component, $key, 'Name', $this->qualifyingRule()),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail("The key \"{$key}\" was accepted.");
            } catch (InvalidPlanDefinition $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
        }

        $this->assertSame(1, DB::table('mlm_plan_components')->count());
        $this->assertSame(0, DB::table('mlm_plan_rules')->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedDrivers(): array
    {
        return [
            'a class name' => ['App\\Drivers\\Bonus'],
            'uppercase' => ['Acme.Bonus'],
            'a callable' => ['strtoupper()'],
            'too long' => [str_repeat('d', 101)],
            'a trailing newline' => ["acme.bonus\n"],
        ];
    }

    #[DataProvider('refusedDrivers')]
    public function test_a_driver_is_a_registered_key_never_a_class_name(string $driver): void
    {
        $this->expectException(InvalidPlanDefinition::class);
        $this->expectExceptionMessage('is not a driver key');

        $this->editor()->addComponent($this->draft(), 'entry', $driver, 'Entry');
    }

    /**
     * @return array<string, array{string|int, array<string, mixed>, string}>
     */
    public static function refusedInput(): array
    {
        return [
            'an empty name' => ['', [], 'a name is 1–255 characters'],
            'a padded name' => [' Entry ', [], 'a name is 1–255 characters'],
            'a name with a newline' => ["Entry\nX", [], 'a name is 1–255 characters'],
            'a name too long' => [str_repeat('n', 256), [], 'a name is 1–255 characters'],
            'parameters as a list' => ['Entry', ['strict', 'lenient'], 'parameters are a JSON object'],
            'an object parameter' => ['Entry', ['mode' => new stdClass], 'which is not JSON data'],
            'a closure parameter' => ['Entry', ['mode' => static fn (): string => 'strict'], 'which is not JSON data'],
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    #[DataProvider('refusedInput')]
    public function test_names_and_parameters_are_checked(string|int $name, array $parameters, string $reason): void
    {
        $this->expectException(InvalidPlanDefinition::class);
        $this->expectExceptionMessage($reason);

        $this->editor()->addComponent($this->draft(), 'entry', 'test.criteria', (string) $name, $parameters);
    }

    public function test_a_negative_position_is_refused(): void
    {
        $this->expectException(InvalidPlanDefinition::class);
        $this->expectExceptionMessage('a position is a whole number');

        $this->editor()->addComponent($this->draft(), 'entry', 'test.criteria', 'Entry', position: -1);
    }

    public function test_keys_that_differ_only_in_case_are_different_keys(): void
    {
        $version = $this->draft();

        // Only a raw write can store a key with capitals; the database still
        // compares it exactly.
        DB::table('mlm_plan_components')->insert([
            'id' => strtolower((string) Str::ulid()), 'plan_version_id' => $version->id, 'key' => 'Entry', 'driver' => 'Test.Criteria',
            'name' => 'Raw', 'parameters' => '{}', 'position' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $component = $this->editor()->addComponent($version, 'entry', 'test.criteria', 'Entry');

        DB::table('mlm_plan_rules')->insert([
            'id' => strtolower((string) Str::ulid()), 'plan_component_id' => $component->id, 'key' => 'Qualifies',
            'name' => 'Raw', 'definition' => $this->qualifyingRule()->toJson(), 'position' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->editor()->addRule($component, 'qualifies', 'Qualifies', $this->qualifyingRule());

        $this->assertSame(1, DB::table('mlm_plan_components')->where('key', 'entry')->count());
        $this->assertSame(1, DB::table('mlm_plan_components')->where('driver', 'test.criteria')->count());
        $this->assertSame(1, DB::table('mlm_plan_rules')->where('key', 'qualifies')->count());
    }

    public function test_stored_data_outside_the_rules_is_refused_when_read(): void
    {
        $component = $this->editor()->addComponent($this->draft(), 'entry', 'test.criteria', 'Entry');
        $rule = $this->editor()->addRule($component, 'qualifies', 'Qualifies', $this->qualifyingRule());

        DB::table('mlm_plan_components')->where('id', $component->id)->update(['parameters' => '["strict"]']);
        DB::table('mlm_plan_rules')->where('id', $rule->id)->update(['definition' => '{"type": "group", "match": "all", "children": []}']);

        try {
            PlanComponent::query()->findOrFail($component->id)->parameters;
            $this->fail('Unreadable parameters were read.');
        } catch (InvalidPlanDefinition $exception) {
            $this->assertStringContainsString('parameters of component "entry" cannot be read', $exception->getMessage());
        }

        $this->expectException(InvalidRuleDefinition::class);
        $this->expectExceptionMessage('a group has at least one child');

        PlanRule::query()->findOrFail($rule->id)->definition;
    }

    public function test_the_plan_itself_holds_no_definition(): void
    {
        $version = $this->validDraft();

        $this->assertFalse(method_exists(Plan::class, 'components'));
        $this->assertSame(1, $version->components()->count());
    }
}
