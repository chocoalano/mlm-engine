<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Payout\PayoutRequestStatus;

/**
 * A payout request step that cannot be taken from where the request is, or
 * a batched request started outside its batch. Nothing changes.
 */
final class InvalidPayoutTransition extends DomainException
{
    public static function from(PayoutRequest $request, PayoutRequestStatus $to): self
    {
        return new self("Payout request [{$request->getKey()}] is {$request->status->value} and cannot become {$to->value}.");
    }

    public static function batched(PayoutRequest $request, string $batch, string $status): self
    {
        return new self("Payout request [{$request->getKey()}] belongs to payout batch [{$batch}], which is {$status}; it starts processing with its batch.");
    }
}
