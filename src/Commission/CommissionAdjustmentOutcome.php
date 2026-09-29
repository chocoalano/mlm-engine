<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

/**
 * What a correction did to its commission.
 *
 * - `cancelled`: no money had moved; the commission was cancelled now.
 * - `reversed`: the commission was posted; its ledger transaction was
 *   reversed now.
 * - `already_cancelled`: it had been cancelled before; nothing changed.
 * - `already_reversed`: it had been reversed before; nothing moved again.
 */
enum CommissionAdjustmentOutcome: string
{
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';
    case AlreadyCancelled = 'already_cancelled';
    case AlreadyReversed = 'already_reversed';
}
