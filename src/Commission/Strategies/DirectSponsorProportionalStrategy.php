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
 * `direct-sponsor.proportional` (ADR-020): the direct sponsor, as of the
 * entry's moment, of the member of every eligible business entry earns the
 * entry's quantity times a configured amount per unit, rounded by the
 * configured mode.
 *
 * Eligibility and attribution are exactly those of `direct-sponsor.fixed`
 * (ADR-019); only the amount differs. The amount per unit is money per one
 * unit of the business measurement — not a percentage. Each award is
 * rounded on its own, and one that rounds to zero is no candidate. Rules are
 * not supported. Reads only; sponsor tree only.
 */
final readonly class DirectSponsorProportionalStrategy implements CommissionStrategy
{
    public const KEY = 'direct-sponsor.proportional';

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

        DirectSponsorProportionalParameters::parse($definition->parameters);
    }

    public function calculate(CommissionCalculationContext $context): iterable
    {
        $parameters = DirectSponsorProportionalParameters::parse($context->definition->parameters);

        foreach (EligibleVolumeEntries::of($context, $parameters->source) as $entry) {
            if (! $parameters->source->reachesMinimum($entry)) {
                continue;
            }

            $sponsor = $this->sponsors->directSponsorAt($entry->member, $entry->effective_at);
            $candidate = $sponsor === null
                ? null
                : ProportionalSourceEntryAward::candidate(self::KEY, $entry, $parameters->source, $sponsor, 1, $parameters->unitAmount, $parameters->rounding);

            if ($candidate !== null) {
                yield $candidate;
            }
        }
    }
}
