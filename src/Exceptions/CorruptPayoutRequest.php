<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\PayoutRequest;

/**
 * A stored payout request that no supported write produces — a status its
 * ledger transactions or references contradict, a reservation or refund
 * that does not move exactly its amount between its wallet and settlement
 * account, or a wallet, member or account that no longer agree. Refused
 * rather than used, and never repaired: correct the rows.
 */
final class CorruptPayoutRequest extends DomainException
{
    public static function because(PayoutRequest $request, string $reason): self
    {
        return new self("Payout request [{$request->getKey()}] is {$request->status->value}, but {$reason}.");
    }
}
