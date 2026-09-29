<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies;

use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionStrategy;
use PandaBear\Mlm\Commission\CommissionStrategyDefinition;
use PandaBear\Mlm\Commission\Strategies\Support\EligibleVolumeEntries;
use PandaBear\Mlm\Commission\Strategies\Support\SourceEntryAward;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;

/**
 * `unilevel.fixed` (ADR-019): fixed awards to the sponsors above the member
 * of every eligible business entry, by sponsor depth.
 *
 * Entries are eligible as for `direct-sponsor.fixed`. For each, the
 * member's sponsor line *as it stood when the entry took effect* is read to
 * the deepest configured depth, and every sponsor at a configured depth
 * earns that depth's amount — one candidate per entry and depth, earned at
 * the entry's moment. The depth is the physical sponsor depth: 1 is the
 * direct sponsor, 2 their sponsor, and so on. A depth that is not configured
 * earns nothing, and nothing moves up to fill it: there is no compression.
 * Sponsors who joined the line after the entry earn nothing from it.
 *
 * Awards are fixed, never a share of the quantity. Rules are not supported.
 * Reads only, on the calculation's connection; sponsor tree only.
 */
final readonly class UnilevelFixedStrategy implements CommissionStrategy
{
    public const KEY = 'unilevel.fixed';

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

        UnilevelFixedParameters::parse($definition->parameters);
    }

    public function calculate(CommissionCalculationContext $context): iterable
    {
        $parameters = UnilevelFixedParameters::parse($context->definition->parameters);
        $deepest = $parameters->maximumDepth();

        foreach (EligibleVolumeEntries::of($context, $parameters->source) as $entry) {
            if (! $parameters->source->reachesMinimum($entry)) {
                continue;
            }

            foreach ($this->sponsors->ancestorsAt($entry->member, $entry->effective_at, $deepest) as $ancestor) {
                $level = $parameters->levels[$ancestor->depth] ?? null;

                if ($level !== null) {
                    yield SourceEntryAward::candidate(self::KEY, $entry, $parameters->source, $ancestor->member, $ancestor->depth, $level->amount);
                }
            }
        }
    }
}
