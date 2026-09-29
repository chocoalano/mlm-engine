<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\Commission;

/**
 * A commission whose binary pairing was partly undone by a reversal
 * (ADR-025) is not posted before that correction's financial share is
 * recorded (ADR-026): it would pay what the pairing no longer earns.
 * Nothing is posted; process the reversals, then post again.
 */
final class UnresolvedBinaryCorrection extends DomainException
{
    /**
     * @param  list<string>  $reversals
     */
    public static function beforePosting(Commission $commission, array $reversals): self
    {
        return new self(sprintf(
            'Commission [%s] cannot be posted: binary corrections by volume reversal%s [%s] have no financial adjustment yet. Call CommissionAdjustmentEngine::processBinaryReversal() for %s, then post it again.',
            $commission->getKey(),
            count($reversals) === 1 ? '' : 's',
            implode(', ', $reversals),
            count($reversals) === 1 ? 'it' : 'each',
        ));
    }
}
