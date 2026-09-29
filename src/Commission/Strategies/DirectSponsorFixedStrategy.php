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
 * `direct-sponsor.fixed` (ADR-019): a fixed award to the direct sponsor of
 * the member of every eligible business entry.
 *
 * An entry is eligible when it is an original volume entry of the
 * configured volume type and source type, effective within the run's range,
 * not reversed before the run's cutoff, and at least the configured minimum
 * quantity. Its member's direct sponsor *at the moment the entry took
 * effect* earns the configured amount, once per entry, earned at that
 * moment. A member with no sponsor then earns nobody anything.
 *
 * The award is fixed: the entry's quantity makes it eligible, and is never
 * multiplied into money. The component's rules are not supported: this
 * variant has no qualification, and refuses rules rather than ignore them.
 * Reads only, on the calculation's connection; sponsor tree only.
 */
final readonly class DirectSponsorFixedStrategy implements CommissionStrategy
{
    public const KEY = 'direct-sponsor.fixed';

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

        DirectSponsorFixedParameters::parse($definition->parameters);
    }

    public function calculate(CommissionCalculationContext $context): iterable
    {
        $parameters = DirectSponsorFixedParameters::parse($context->definition->parameters);

        foreach (EligibleVolumeEntries::of($context, $parameters->source) as $entry) {
            if (! $parameters->source->reachesMinimum($entry)) {
                continue;
            }

            $sponsor = $this->sponsors->directSponsorAt($entry->member, $entry->effective_at);

            if ($sponsor !== null) {
                yield SourceEntryAward::candidate(self::KEY, $entry, $parameters->source, $sponsor, 1, $parameters->amount);
            }
        }
    }
}
