<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Models\Commission;

final class InvalidCommissionTransition extends DomainException
{
    public static function from(Commission $commission, CommissionStatus $to): self
    {
        $allowed = array_map(
            static fn (CommissionStatus $status): string => $status->value,
            $commission->status->nextSteps(),
        );

        return new self(sprintf(
            'Commission [%s] is %s and cannot become %s; from %s it can only become %s.',
            $commission->getKey(),
            $commission->status->value,
            $to->value,
            $commission->status->value,
            $allowed === [] ? 'nothing else' : implode(' or ', $allowed),
        ));
    }
}
