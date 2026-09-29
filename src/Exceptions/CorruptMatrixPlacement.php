<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * Stored matrix structure that no supported write produces — a position
 * that disagrees with its placement edge, a slot outside its network's
 * width, a position of another program's network, a placement across
 * programs. Refused rather than read into a wrong network, and never
 * repaired: correct the rows.
 */
final class CorruptMatrixPlacement extends DomainException
{
    public static function parentMismatch(string $position, string $parent, string $edge, string $edgeParent): self
    {
        return new self("Matrix position [{$position}] names parent [{$parent}], but its placement edge [{$edge}] is under [{$edgeParent}]; a matrix parent is always the placement parent.");
    }

    public static function slot(string $position, mixed $slot, int $width): self
    {
        return new self("Matrix position [{$position}] has slot ".var_export($slot, true)."; its network's slots are 1 to {$width}.");
    }

    public static function otherNetwork(string $position, string $network, string $expected): self
    {
        return new self("Matrix position [{$position}] belongs to matrix network [{$network}], not its program's network [{$expected}].");
    }

    public static function crossProgram(string $edge, string $member, string $parent): self
    {
        return new self("Placement edge [{$edge}] places member [{$member}] under member [{$parent}] of another program; the matrix stays within one program.");
    }

    public static function cycle(string $edge): self
    {
        return new self("Placement edge [{$edge}] would close a cycle in the matrix; its stored paths do not match the placements.");
    }
}
