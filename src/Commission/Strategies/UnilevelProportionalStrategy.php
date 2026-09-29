<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies;

use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionStrategy;
use PandaBear\Mlm\Commission\CommissionStrategyDefinition;
use PandaBear\Mlm\Commission\Strategies\Support\EligibleVolumeEntries;
use PandaBear\Mlm\Commission\Strategies\Support\ProportionalSourceEntryAward;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;

/**
 * `unilevel.proportional` (ADR-020): the sponsors above the member of every
 * eligible business entry, at configured physical depths, earn the entry's
 * quantity times their depth's amount per unit, rounded by the component's
 * one rounding mode.
 *
 * Eligibility, the sponsor line as it stood at the entry's moment, physical
 * depths, gaps and the absence of compression are exactly those of
 * `unilevel.fixed` (ADR-019). Each entry and depth is rounded on its own; a
 * depth whose award rounds to zero earns nothing, and the other depths are
 * unaffected. Rules are not supported. Reads only; sponsor tree only.
 */
final readonly class UnilevelProportionalStrategy implements CommissionStrategy
{
    public const KEY = 'unilevel.proportional';

    public function __construct(private SponsorGenealogy $sponsors) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function validate(CommissionStrategyDefinition $definition): void
    {
        if ($definition->rules !== []) {
            throw InvalidPlanDefinition::input(self::KEY.' rules', 'this strategy takes no rules; remove them rather than have them ignored.');
        }

        UnilevelProportionalParameters::parse($definition->parameters);
    }

    public function calculate(CommissionCalculationContext $context): iterable
    {
        $parameters = UnilevelProportionalParameters::parse($context->definition->parameters);
        $deepest = $parameters->maximumDepth();

        foreach (EligibleVolumeEntries::of($context, $parameters->source) as $entry) {
            if (! $parameters->source->reachesMinimum($entry)) {
                continue;
            }

            foreach ($this->sponsors->ancestorsAt($entry->member, $entry->effective_at, $deepest) as $ancestor) {
                $level = $parameters->levels[$ancestor->depth] ?? null;
                $candidate = $level === null
                    ? null
                    : ProportionalSourceEntryAward::candidate(self::KEY, $entry, $parameters->source, $ancestor->member, $ancestor->depth, $level->unitAmount, $parameters->rounding);

                if ($candidate !== null) {
                    yield $candidate;
                }
            }
        }
    }
}
