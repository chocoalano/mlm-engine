<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\PayoutRequest;

/**
 * A payout request larger than its wallet's balance when it is approved
 * (ADR-030). It stays requested; nothing is reserved.
 */
final class InsufficientPayoutBalance extends DomainException
{
    public static function forRequest(PayoutRequest $request, string $balance): self
    {
        return new self("Payout request [{$request->getKey()}] of {$request->amount->value()} cannot be approved: wallet [{$request->wallet_id}] holds {$balance}.");
    }
}
