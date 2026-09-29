<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies;

use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionStrategy;
use PandaBear\Mlm\Commission\CommissionStrategyDefinition;
use PandaBear\Mlm\Commission\Strategies\Support\EligibleVolumeEntries;
use PandaBear\Mlm\Commission\Strategies\Support\SourceEntryAward;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Matrix\MatrixAncestry;

/**
 * `matrix.fixed` (ADR-027): fixed awards to the members above the member of
 * every eligible business entry in the matrix, by matrix depth.
 *
 * Entries are eligible exactly as for `unilevel.fixed` (ADR-019): original
 * entries of the volume and source type, effective in the run's range, at
 * least the minimum quantity, not reversed before the run's `until`. For
 * each, the member's matrix line *as it stood when the entry took effect*
 * is read to the deepest configured depth, and every matrix ancestor at a
 * configured depth earns that depth's amount — one candidate per entry and
 * depth, keyed `volume-entry:<entry>:depth:<depth>`, earned at the entry's
 * moment and recording the entry as its source, so a later reversal claws
 * it back like any source-entry commission (ADR-021). The depth is the
 * physical matrix depth: 1 is the matrix parent. A depth that is not
 * configured earns nothing, and nothing moves up to fill it: there is no
 * compression. Members adopted into the matrix after the entry earn nothing
 * from it.
 *
 * Awards are fixed, never a share of the quantity. Rules are not supported.
 * Reads only, on the calculation's connection; matrix paths only — never
 * sponsorship, the generic placement or the binary tree. Stateless.
 */
final readonly class MatrixFixedStrategy implements CommissionStrategy
{
    public const KEY = 'matrix.fixed';

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

        MatrixFixedParameters::parse($definition->parameters);
    }

    public function calculate(CommissionCalculationContext $context): iterable
    {
        $parameters = MatrixFixedParameters::parse($context->definition->parameters);
        $entries = EligibleVolumeEntries::reachingMinimum($context, $parameters->source);

        foreach ($this->ancestry->ofEntries($context->connection, $entries, $parameters->maximumDepth()) as [$entry, $ancestor]) {
            $amount = $parameters->levels[$ancestor->depth] ?? null;

            if ($amount !== null) {
                yield SourceEntryAward::candidate(self::KEY, $entry, $parameters->source, $ancestor->member, $ancestor->depth, $amount, [
                    'matrix' => ['recipient_member_id' => (string) $ancestor->member->getKey(), 'depth' => $ancestor->depth],
                    'calculation' => ['amount' => $amount->value()],
                ]);
            }
        }
    }
}
