<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Payout;

/**
 * Where a payout request is (ADR-030).
 *
 * - `requested`: recorded; no money has moved. It may be cancelled.
 * - `approved`: its amount is reserved — moved from the wallet to the
 *   settlement account.
 * - `processing`: the external transfer has begun.
 * - `settled`: the application confirms it succeeded, with the external
 *   reference. Final.
 * - `failed`: it did not happen; the reservation was reversed to the
 *   wallet. Final.
 * - `cancelled`: withdrawn before any reservation. Final.
 */
enum PayoutRequestStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Processing = 'processing';
    case Settled = 'settled';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function nextSteps(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Cancelled],
            self::Approved => [self::Processing, self::Failed],
            self::Processing => [self::Settled, self::Failed],
            self::Settled, self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->nextSteps(), true);
    }

    /**
     * Whether its amount is reserved and not returned.
     */
    public function holdsReservation(): bool
    {
        return in_array($this, [self::Approved, self::Processing, self::Settled], true);
    }
}
