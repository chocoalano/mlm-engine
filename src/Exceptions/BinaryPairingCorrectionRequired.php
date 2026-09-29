<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A reversal reaches binary carry that an earlier pairing already consumed
 * (ADR-023). Taking it back would rewrite a pairing that has already earned
 * a commission, so the run stops, and nothing of it is kept: the paid
 * quantity needs an explicit correction first.
 */
final class BinaryPairingCorrectionRequired extends DomainException
{
    public static function consumed(string $component, string $member, string $side, string $entry, string $consumed, string $reversal): self
    {
        return new self(sprintf(
            'Volume entry [%s] is reversed by [%s], but binary pairing component [%s] has already paired %s of it in the %s leg of member [%s]. '
            .'Consumed binary carry is not taken back automatically; it needs a binary correction. Nothing was calculated.',
            $entry,
            $reversal,
            $component,
            $consumed,
            $side,
            $member,
        ));
    }
}
