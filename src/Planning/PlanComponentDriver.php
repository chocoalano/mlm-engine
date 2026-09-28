<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning;

use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;

/**
 * Trusted code a plan component selects by key: the component stores the
 * key, never a class name, and the key finds this already-registered object
 * in the `PlanComponentDriverRegistry`.
 *
 * For now a driver only judges its components' configuration, when a plan
 * version is validated. What a component does — qualify, rank, pay — belongs
 * to contracts of later phases, not to this one.
 */
interface PlanComponentDriver
{
    /**
     * The stable key components store: 1–100 lowercase letters, digits, ".",
     * "-" or "_", starting with a letter or digit. Namespace your own:
     * "acme.bonus".
     */
    public function key(): string;

    /**
     * Refuses a component — its parameters, its rules — this driver cannot
     * work with. Called with the complete, read-only component, whose rules
     * are already known to be well-formed. Must not write anything.
     *
     * @throws InvalidPlanDefinition
     */
    public function validate(PlanComponentDefinition $component): void;
}
