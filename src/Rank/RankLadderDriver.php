<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Rank;

use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Planning\PlanComponentDefinition;
use PandaBear\Mlm\Planning\PlanComponentDriver;
use PandaBear\Mlm\Planning\PlanRuleDefinition;

/**
 * The package's rank ladder (ADR-016): a plan component whose rules are its
 * ranks. Each rule is one rank — its key the rank's stable key, its name the
 * rank's name, its position the rank's place on the ladder, a larger position
 * a higher rank, and its definition what the rank requires.
 *
 * `RankEngine` evaluates a ladder; this driver only judges one, when its
 * version is validated:
 *
 * - it takes no parameters: none is defined yet;
 * - it holds at least one rank;
 * - no two of its ranks share a position, so the ladder's order is the one
 *   the plan chose, never one ids decide. Gaps are fine.
 *
 * Registered by the package under "rank.ladder", through the same
 * `register()` an application uses for its own drivers.
 */
final readonly class RankLadderDriver implements PlanComponentDriver
{
    public const KEY = 'rank.ladder';

    public function key(): string
    {
        return self::KEY;
    }

    public function validate(PlanComponentDefinition $component): void
    {
        $problem = self::problem(
            $component->driver,
            $component->parameters,
            array_map(static fn (PlanRuleDefinition $rule): array => [$rule->key, $rule->position], $component->rules),
        );

        if ($problem !== null) {
            throw InvalidPlanDefinition::input('rank ladder', $problem);
        }
    }

    /**
     * @internal
     *
     * What is wrong with a ladder of this driver, these parameters and these
     * ranks, or null if nothing is. `RankEngine` asks again of the stored
     * ladder before it trusts it.
     *
     * @param  array<string, mixed>  $parameters
     * @param  list<array{string, int}>  $ranks  the key and position of every rank
     */
    public static function problem(string $driver, array $parameters, array $ranks): ?string
    {
        if ($driver !== self::KEY) {
            return sprintf('its driver is "%s", not "%s".', $driver, self::KEY);
        }

        if ($parameters !== []) {
            return sprintf(
                'a rank ladder takes no parameters, but it has %s.',
                implode(', ', array_map(static fn (int|string $name): string => '"'.$name.'"', array_keys($parameters))),
            );
        }

        if ($ranks === []) {
            return 'a rank ladder needs at least one rank, but it has no rules.';
        }

        $taken = [];

        foreach ($ranks as [$key, $position]) {
            if (isset($taken[$position])) {
                return sprintf('ranks "%s" and "%s" share position %d; every rank of a ladder needs a position of its own.', $taken[$position], $key, $position);
            }

            $taken[$position] = $key;
        }

        return null;
    }
}
