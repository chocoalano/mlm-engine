<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A calculation run or commission was written through its model. Runs and
 * commissions are written by the calculation engine; a commission's
 * lifecycle moves only through its lifecycle and poster services.
 */
final class ImmutableCalculationRecord extends DomainException
{
    /**
     * @param  class-string  $model
     */
    public static function outsideWriter(string $model, string $writers): self
    {
        return new self(sprintf('%s rows are read-only through Eloquent: they are written by %s.', $model, $writers));
    }
}
