<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

/**
 * What a stateful strategy calculated: its candidates, and the state
 * transition to commit with them.
 */
final readonly class StatefulCommissionCalculation
{
    /**
     * @param  iterable<CommissionCandidate>  $candidates
     */
    public function __construct(
        public iterable $candidates,
        public CommissionStateTransition $transition,
    ) {}
}
