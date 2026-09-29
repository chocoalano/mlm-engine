<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

/**
 * Where a commission is in its life. A calculation stores it CALCULATED; it
 * is reviewed — PENDING, then APPROVED — and only then POSTED to the ledger,
 * which a REVERSAL can undo. Until it is posted it can be CANCELLED, which
 * moves no money.
 *
 * Deliberately a prefix of a longer life: held, available and paid need
 * hold, availability and payout rules that do not exist yet.
 */
enum CommissionStatus: string
{
    case Calculated = 'calculated';
    case Pending = 'pending';
    case Approved = 'approved';
    case Posted = 'posted';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';

    /**
     * @return list<self>
     */
    public function nextSteps(): array
    {
        return match ($this) {
            self::Calculated => [self::Pending, self::Cancelled],
            self::Pending => [self::Approved, self::Cancelled],
            self::Approved => [self::Posted, self::Cancelled],
            self::Posted => [self::Reversed],
            self::Cancelled, self::Reversed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->nextSteps(), true);
    }

    /**
     * The column that records when a commission reached this status.
     */
    public function stampColumn(): ?string
    {
        return match ($this) {
            self::Calculated => null,
            self::Pending => 'pending_at',
            self::Approved => 'approved_at',
            self::Posted => 'posted_at',
            self::Cancelled => 'cancelled_at',
            self::Reversed => 'reversed_at',
        };
    }
}
