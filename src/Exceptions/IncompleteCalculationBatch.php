<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\Commission;

/**
 * A commission of a hybrid batch that is still open (ADR-028) is not
 * posted: the batch's other components have not all been calculated, so
 * none of its money moves yet. Nothing is posted; complete the batch, then
 * post again.
 */
final class IncompleteCalculationBatch extends DomainException
{
    public static function beforePosting(Commission $commission, string $batch): self
    {
        return new self("Commission [{$commission->getKey()}] cannot be posted: it belongs to calculation batch [{$batch}], which is still open. Calculate the batch again to complete it, then post.");
    }
}
