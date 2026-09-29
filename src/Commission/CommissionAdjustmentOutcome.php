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
 * - `recorded`: a binary correction (ADR-026) that moved no money: it
 *   reduces what posting the commission may still move, or was too small
 *   to be worth a financial millionth. The commission's status is
 *   unchanged.
 * - `adjusted`: a binary correction of a posted commission that moved part
 *   of its money back, in a ledger transaction of its own. The commission
 *   stays posted; its original posting is untouched.
 */
enum CommissionAdjustmentOutcome: string
{
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';
    case AlreadyCancelled = 'already_cancelled';
    case AlreadyReversed = 'already_reversed';
    case Recorded = 'recorded';
    case Adjusted = 'adjusted';
}
