<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies;

use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionStrategy;
use PandaBear\Mlm\Commission\CommissionStrategyDefinition;
use PandaBear\Mlm\Commission\Strategies\Support\EligibleVolumeEntries;
use PandaBear\Mlm\Commission\Strategies\Support\ProportionalSourceEntryAward;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Matrix\MatrixAncestry;

/**
 * `matrix.proportional` (ADR-027): the members above the member of every
 * eligible business entry in the matrix, at configured physical matrix
 * depths, earn the entry's quantity times their depth's amount per unit,
 * rounded by the component's one rounding mode (ADR-020) — an amount per
 * unit of quantity, not a percentage.
 *
 * Eligibility, the matrix line as it stood at the entry's moment, physical
 * depths, gaps, the absence of compression, keys, provenance and clawback
 * are exactly those of `matrix.fixed`. Each entry and depth is rounded on
 * its own; a depth whose award rounds to zero earns nothing, and the other
 * depths are unaffected. Rules are not supported. Reads only; matrix paths
 * only. Stateless.
 */
final readonly class MatrixProportionalStrategy implements CommissionStrategy
{
    public const KEY = 'matrix.proportional';

    public function __construct(private MatrixAncestry $ancestry) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function validate(CommissionStrategyDefinition $definition): void
    {
        if ($definition->rules !== []) {
            throw InvalidPlanDefinition::input(self::KEY.' rules', 'this strategy takes no rules; remove them rather than have them ignored.');
        }

        MatrixProportionalParameters::parse($definition->parameters);
    }

    public function calculate(CommissionCalculationContext $context): iterable
    {
        $parameters = MatrixProportionalParameters::parse($context->definition->parameters);
        $entries = EligibleVolumeEntries::reachingMinimum($context, $parameters->source);

        foreach ($this->ancestry->ofEntries($context->connection, $entries, $parameters->maximumDepth()) as [$entry, $ancestor]) {
            $unitAmount = $parameters->levels[$ancestor->depth] ?? null;
            $candidate = $unitAmount === null ? null : ProportionalSourceEntryAward::candidate(
                self::KEY,
                $entry,
                $parameters->source,
                $ancestor->member,
                $ancestor->depth,
                $unitAmount,
                $parameters->rounding,
                ['matrix' => ['recipient_member_id' => (string) $ancestor->member->getKey(), 'depth' => $ancestor->depth]],
            );

            if ($candidate !== null) {
                yield $candidate;
            }
        }
    }
}
