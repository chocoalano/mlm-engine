<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use PandaBear\Mlm\Exceptions\InvalidCommissionTransition;
use PandaBear\Mlm\Models\Commission;

/**
 * Reviews commissions, one at a time: CALCULATED becomes PENDING, PENDING
 * becomes APPROVED, and anything not yet posted can be CANCELLED — which
 * moves no money and ends its life. Posting and reversing are
 * `CommissionPoster`'s: they move money.
 *
 * Every move re-reads the commission and locks its row, and decides from
 * the stored status, never from the instance passed in.
 */
final readonly class CommissionLifecycle
{
    /**
     * @throws InvalidCommissionTransition
     */
    public function markPending(Commission $commission): Commission
    {
        return $this->move($commission, CommissionStatus::Pending);
    }

    /**
     * @throws InvalidCommissionTransition
     */
    public function approve(Commission $commission): Commission
    {
        return $this->move($commission, CommissionStatus::Approved);
    }

    /**
     * @throws InvalidCommissionTransition
     */
    public function cancel(Commission $commission): Commission
    {
        return $this->move($commission, CommissionStatus::Cancelled);
    }

    private function move(Commission $commission, CommissionStatus $to): Commission
    {
        $db = $commission->getConnection();

        return $db->transaction(static function () use ($db, $commission, $to): Commission {
            $current = CommissionStatusWriter::lock($db, $commission);

            if (! $current->status->canTransitionTo($to)) {
                throw InvalidCommissionTransition::from($current, $to);
            }

            return CommissionStatusWriter::move($db, $current, $to, $current->freshTimestamp());
        });
    }
}
