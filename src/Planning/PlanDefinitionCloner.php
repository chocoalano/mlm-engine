<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning;

use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;

/**
 * How a locked definition changes: copied into a new draft, which is then
 * edited and validated in its turn. The source is never touched.
 */
final readonly class PlanDefinitionCloner
{
    public function __construct(private PlanVersionLifecycle $lifecycle) {}

    /**
     * A new draft of the source's plan — numbered by the lifecycle as any
     * draft is — holding a copy of the source's whole definition: every
     * component and rule, with their keys, names, drivers, parameters,
     * definitions and positions, under new ids. No status or timestamp of the
     * source is copied. All of it, or nothing: one transaction.
     *
     * The copy is inert data: no driver runs, so a definition naming a driver
     * that is not registered now still copies — and the draft cannot be
     * validated until it is. Parameters and rules are read back through the
     * same rules they were written under; stored data outside them, which
     * only a raw write produces, stops the copy.
     *
     * @throws InvalidPlanDefinition
     * @throws InvalidRuleDefinition
     */
    public function cloneToNewDraft(PlanVersion $source): PlanVersion
    {
        $db = $source->getConnection();

        return $db->transaction(function () use ($source, $db): PlanVersion {
            // Plan first, then version: the order draft() and activate()
            // lock in. Held until the copy commits, so a draft source cannot
            // change underneath it.
            $plan = $source->newQuery()->findOrFail($source->getKey())->plan()->lockForUpdate()->firstOrFail();
            $source = $source->newQuery()->whereKey($source->getKey())->sharedLock()->firstOrFail();

            $draft = $this->lifecycle->draft($plan);

            $components = $db->table('mlm_plan_components')->where('plan_version_id', $source->getKey())->orderBy('position')->orderBy('id')->get();

            foreach ($components as $component) {
                $copy = new PlanComponent;
                $id = $copy->newUniqueId();
                $now = $copy->freshTimestamp();

                $db->table('mlm_plan_components')->insert([
                    'id' => $id,
                    'plan_version_id' => $draft->getKey(),
                    'key' => $component->key,
                    'driver' => $component->driver,
                    'name' => $component->name,
                    'parameters' => DefinitionInput::json(DefinitionInput::stored($component->parameters, "parameters of component \"{$component->key}\"")),
                    'position' => $component->position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $rules = $db->table('mlm_plan_rules')->where('plan_component_id', $component->id)->orderBy('position')->orderBy('id')->get();

                foreach ($rules as $rule) {
                    $db->table('mlm_plan_rules')->insert([
                        'id' => (new PlanRule)->newUniqueId(),
                        'plan_component_id' => $id,
                        'key' => $rule->key,
                        'name' => $rule->name,
                        'definition' => RuleDefinition::fromJson($rule->definition)->toJson(),
                        'position' => $rule->position,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            return $draft->refresh();
        });
    }
}
