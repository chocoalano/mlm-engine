<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\CommissionPeriod;

/**
 * A commission period requested under an idempotency key a period of the
 * program already holds, with other facts: another plan version, source
 * account, range or release moment. A key names one period. Nothing is
 * created.
 */
final class ConflictingCommissionPeriod extends DomainException
{
    /**
     * @param  list<string>  $conflicts
     */
    public static function forKey(CommissionPeriod $period, array $conflicts): self
    {
        return new self("Commission period [{$period->getKey()}] already holds idempotency key \"{$period->idempotency_key}\" in program [{$period->program_id}]; this request differs in ".implode(', ', $conflicts).'.');
    }
}
