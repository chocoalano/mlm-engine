<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Correction;

/**
 * Where one binary carry lot of a reversed entry stands (ADR-024):
 *
 * - `unconsumed`: nothing of it was paired; all of it remains;
 * - `partially_consumed`: some of it was paired, some remains;
 * - `fully_consumed`: all of it was paired;
 * - `already_removed`: this reversal already took it back, unpaired.
 */
enum BinaryReversalLotState: string
{
    case Unconsumed = 'unconsumed';
    case PartiallyConsumed = 'partially_consumed';
    case FullyConsumed = 'fully_consumed';
    case AlreadyRemoved = 'already_removed';

    /**
     * Whether paired quantity — and possibly a commission — depends on it.
     */
    public function isConsumed(): bool
    {
        return $this === self::PartiallyConsumed || $this === self::FullyConsumed;
    }
}
