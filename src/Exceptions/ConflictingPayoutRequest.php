<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\PayoutRequest;

/**
 * A payout request, or a step of one, asked for again with other facts: a
 * key already used for another request, or a settlement, failure or
 * cancellation replayed with another reference, reason or moment. Nothing
 * changes.
 */
final class ConflictingPayoutRequest extends DomainException
{
    /**
     * @param  list<string>  $conflicts
     */
    public static function forKey(PayoutRequest $request, array $conflicts): self
    {
        return new self("Payout request [{$request->getKey()}] already holds idempotency key \"{$request->idempotency_key}\" in program [{$request->program_id}]; this request differs in ".implode(', ', $conflicts).'.');
    }

    /**
     * @param  list<string>  $conflicts
     */
    public static function step(PayoutRequest $request, array $conflicts): self
    {
        return new self("Payout request [{$request->getKey()}] is already {$request->status->value}; this request differs in ".implode(', ', $conflicts).'.');
    }

    public static function settlementReference(string $reference, string $other): self
    {
        return new self("Settlement reference \"{$reference}\" already settled payout request [{$other}]; one external settlement settles one request.");
    }
}
