<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning;

use Closure;
use Illuminate\Database\Connection;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\PlanVersionNotMutable;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;

/**
 * The only supported way to change a plan version's definition: its
 * components and their rules.
 *
 * Every change re-reads the version and locks its row — the row
 * `PlanVersionLifecycle::markValidated()` locks — and refuses unless the
 * version is still a draft, whatever a model instance in memory says. So no
 * change can land in a version once it has been validated: to change a
 * validated definition, clone it into a new draft.
 *
 * Checks the shape of what it writes — keys, names, positions, parameters,
 * rule language — but not whether drivers and metrics exist: a draft may be
 * incomplete. `markValidated()` checks the whole definition.
 *
 * Writes go through the query builder: the models are read-only through
 * Eloquent.
 */
final readonly class PlanDefinitionEditor
{
    private const COMPONENTS = 'mlm_plan_components';

    private const RULES = 'mlm_plan_rules';

    /**
     * @param  array<string, mixed>  $parameters  inert data for the driver: a JSON object
     * @param  int|null  $position  null for after the version's last component
     *
     * @throws PlanVersionNotMutable
     * @throws InvalidPlanDefinition
     */
    public function addComponent(PlanVersion $version, string $key, string $driver, string $name, array $parameters = [], ?int $position = null): PlanComponent
    {
        $key = DefinitionInput::key('component key', $key);
        $driver = DefinitionInput::driver($driver);
        $name = DefinitionInput::name('component name', $name);
        $parameters = DefinitionInput::parameters('component parameters', $parameters);
        $position = $position === null ? null : DefinitionInput::position('component position', $position);

        return $this->inDraft($version, function (PlanVersion $draft, Connection $db) use ($key, $driver, $name, $parameters, $position): PlanComponent {
            if ($db->table(self::COMPONENTS)->where('plan_version_id', $draft->getKey())->where('key', $key)->exists()) {
                throw InvalidPlanDefinition::duplicateComponent($draft, $key);
            }

            $component = new PlanComponent;
            $id = $component->newUniqueId();
            $now = $component->freshTimestamp();

            $db->table(self::COMPONENTS)->insert([
                'id' => $id,
                'plan_version_id' => $draft->getKey(),
                'key' => $key,
                'driver' => $driver,
                'name' => $name,
                'parameters' => DefinitionInput::json($parameters),
                'position' => $position ?? $this->nextPosition($db, self::COMPONENTS, 'plan_version_id', $draft->getKey()),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return PlanComponent::on($db->getName())->findOrFail($id);
        });
    }

    /**
     * Changes what is given; null leaves it as it is. A component's key is
     * its identity and does not change: remove it and add another instead.
     *
     * @param  array<string, mixed>|null  $parameters
     *
     * @throws PlanVersionNotMutable
     * @throws InvalidPlanDefinition
     */
    public function updateComponent(PlanComponent $component, ?string $name = null, ?string $driver = null, ?array $parameters = null, ?int $position = null): PlanComponent
    {
        $changes = array_filter([
            'name' => $name === null ? null : DefinitionInput::name('component name', $name),
            'driver' => $driver === null ? null : DefinitionInput::driver($driver),
            'parameters' => $parameters === null ? null : DefinitionInput::json(DefinitionInput::parameters('component parameters', $parameters)),
            'position' => $position === null ? null : DefinitionInput::position('component position', $position),
        ], static fn (mixed $value): bool => $value !== null);

        return $this->inDraftOf($component, function (PlanVersion $draft, Connection $db, PlanComponent $current) use ($changes): PlanComponent {
            if ($changes !== []) {
                $db->table(self::COMPONENTS)->where('id', $current->getKey())->update([...$changes, 'updated_at' => $current->freshTimestamp()]);
            }

            return PlanComponent::on($db->getName())->findOrFail($current->getKey());
        });
    }

    /**
     * Removes the component and its rules.
     *
     * @throws PlanVersionNotMutable
     */
    public function removeComponent(PlanComponent $component): void
    {
        $this->inDraftOf($component, static function (PlanVersion $draft, Connection $db, PlanComponent $current): void {
            $db->table(self::RULES)->where('plan_component_id', $current->getKey())->delete();
            $db->table(self::COMPONENTS)->where('id', $current->getKey())->delete();
        });
    }

    /**
     * @param  int|null  $position  null for after the component's last rule
     *
     * @throws PlanVersionNotMutable
     * @throws InvalidPlanDefinition
     */
    public function addRule(PlanComponent $component, string $key, string $name, RuleDefinition $definition, ?int $position = null): PlanRule
    {
        $key = DefinitionInput::key('rule key', $key);
        $name = DefinitionInput::name('rule name', $name);
        $position = $position === null ? null : DefinitionInput::position('rule position', $position);

        return $this->inDraftOf($component, function (PlanVersion $draft, Connection $db, PlanComponent $current) use ($key, $name, $definition, $position): PlanRule {
            if ($db->table(self::RULES)->where('plan_component_id', $current->getKey())->where('key', $key)->exists()) {
                throw InvalidPlanDefinition::duplicateRule($current->key, $key);
            }

            $rule = new PlanRule;
            $id = $rule->newUniqueId();
            $now = $rule->freshTimestamp();

            $db->table(self::RULES)->insert([
                'id' => $id,
                'plan_component_id' => $current->getKey(),
                'key' => $key,
                'name' => $name,
                'definition' => $definition->toJson(),
                'position' => $position ?? $this->nextPosition($db, self::RULES, 'plan_component_id', $current->getKey()),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return PlanRule::on($db->getName())->findOrFail($id);
        });
    }

    /**
     * Changes what is given; null leaves it as it is. A rule's key is its
     * identity and does not change.
     *
     * @throws PlanVersionNotMutable
     * @throws InvalidPlanDefinition
     */
    public function updateRule(PlanRule $rule, ?string $name = null, ?RuleDefinition $definition = null, ?int $position = null): PlanRule
    {
        $changes = array_filter([
            'name' => $name === null ? null : DefinitionInput::name('rule name', $name),
            'definition' => $definition?->toJson(),
            'position' => $position === null ? null : DefinitionInput::position('rule position', $position),
        ], static fn (mixed $value): bool => $value !== null);

        return $this->inDraftOfRule($rule, static function (Connection $db, PlanRule $current) use ($changes): PlanRule {
            if ($changes !== []) {
                $db->table(self::RULES)->where('id', $current->getKey())->update([...$changes, 'updated_at' => $current->freshTimestamp()]);
            }

            return PlanRule::on($db->getName())->findOrFail($current->getKey());
        });
    }

    /**
     * @throws PlanVersionNotMutable
     */
    public function removeRule(PlanRule $rule): void
    {
        $this->inDraftOfRule($rule, static function (Connection $db, PlanRule $current): void {
            $db->table(self::RULES)->where('id', $current->getKey())->delete();
        });
    }

    /**
     * Runs `$change` in a transaction, on a freshly read and locked copy of
     * the version, if it is a draft.
     *
     * @template TResult
     *
     * @param  Closure(PlanVersion, Connection): TResult  $change
     * @return TResult
     */
    private function inDraft(PlanVersion $version, Closure $change): mixed
    {
        return $this->locked($version->getConnection(), (string) $version->getKey(), $change);
    }

    /**
     * @template TResult
     *
     * @param  Closure(PlanVersion, Connection, PlanComponent): TResult  $change
     * @return TResult
     */
    private function inDraftOf(PlanComponent $component, Closure $change): mixed
    {
        // A component never moves to another version: its version is read
        // once, then the component re-read under the version's lock, in case
        // it was removed meanwhile.
        $versionId = (string) $component->newQuery()->whereKey($component->getKey())->firstOrFail()->plan_version_id;

        return $this->locked($component->getConnection(), $versionId, static fn (PlanVersion $draft, Connection $db): mixed => $change(
            $draft,
            $db,
            PlanComponent::on($db->getName())->whereKey($component->getKey())->firstOrFail(),
        ));
    }

    /**
     * @template TResult
     *
     * @param  Closure(Connection, PlanRule): TResult  $change
     * @return TResult
     */
    private function inDraftOfRule(PlanRule $rule, Closure $change): mixed
    {
        $componentId = (string) $rule->newQuery()->whereKey($rule->getKey())->firstOrFail()->plan_component_id;
        $component = PlanComponent::on($rule->getConnection()->getName())->whereKey($componentId)->firstOrFail();

        return $this->inDraftOf($component, static fn (PlanVersion $draft, Connection $db): mixed => $change(
            $db,
            PlanRule::on($db->getName())->whereKey($rule->getKey())->firstOrFail(),
        ));
    }

    /**
     * @template TResult
     *
     * @param  Closure(PlanVersion, Connection): TResult  $change
     * @return TResult
     */
    private function locked(Connection $db, string $versionId, Closure $change): mixed
    {
        return $db->transaction(static function () use ($db, $versionId, $change): mixed {
            $draft = PlanVersion::on($db->getName())->whereKey($versionId)->lockForUpdate()->firstOrFail();
            $draft->assertMutable();

            return $change($draft, $db);
        });
    }

    private function nextPosition(Connection $db, string $table, string $owner, string $ownerId): int
    {
        return (int) $db->table($table)->where($owner, $ownerId)->max('position') + 1;
    }
}
