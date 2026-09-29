<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * Stored binary structure that no supported write produces — a position
 * that disagrees with its placement edge, a side spelled otherwise, a
 * placement across programs. Refused rather than read into a wrong network,
 * and never repaired: correct the rows.
 */
final class CorruptBinaryPlacement extends DomainException
{
    public static function parentMismatch(string $position, string $parent, string $edge, string $edgeParent): self
    {
        return new self("Binary position [{$position}] names parent [{$parent}], but its placement edge [{$edge}] is under [{$edgeParent}]; a binary parent is always the placement parent.");
    }

    public static function side(string $position, mixed $side): self
    {
        return new self("Binary position [{$position}] has side ".var_export($side, true).'; a binary side is exactly "left" or "right".');
    }

    public static function crossProgram(string $edge, string $member, string $parent): self
    {
        return new self("Placement edge [{$edge}] places member [{$member}] under member [{$parent}] of another program; the binary tree stays within one program.");
    }

    public static function cycle(string $edge): self
    {
        return new self("Placement edge [{$edge}] would close a cycle in the binary tree; its stored paths do not match the placements.");
    }
}
