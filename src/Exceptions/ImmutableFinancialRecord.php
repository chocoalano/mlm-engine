<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A wallet, ledger account, transaction or posting was written through its
 * model. Financial rows are written by the package's managers and recorder
 * alone, and never changed: a correction is a new transaction.
 */
final class ImmutableFinancialRecord extends DomainException
{
    /**
     * @param  class-string  $model
     * @param  class-string  $writer
     */
    public static function outsideWriter(string $model, string $writer): self
    {
        return new self(sprintf('%s rows are written only by %s and never changed; a correction is a new ledger transaction.', $model, $writer));
    }
}
