<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\Member;

/**
 * A matrix placement that cannot be made: the program has no matrix
 * network, the slot is not one of the network's, it is taken, the edge is
 * already in another slot, or it is not a stored placement. Nothing is
 * placed or adopted.
 */
final class InvalidMatrixPlacement extends DomainException
{
    public static function slot(mixed $slot): self
    {
        return new self('A matrix slot is a PHP integer of 1 or more; '.var_export($slot, true).' given.');
    }

    public static function slotBeyondWidth(int $slot, int $width): self
    {
        return new self("Matrix slot {$slot} does not exist: the matrix is {$width} wide, so its slots are 1 to {$width}.");
    }

    public static function noNetwork(string $program): self
    {
        return new self("Program [{$program}] has no matrix network; configure one through PandaBear\Mlm\Matrix\MatrixNetworkManager first.");
    }

    public static function slotOccupied(Member $parent, int $slot, string $occupant): self
    {
        return new self("Matrix slot {$slot} of member [{$parent->getKey()}] is already taken by member [{$occupant}]; a slot holds one member.");
    }

    public static function alreadyInOtherSlot(string $edge, int $slot, int $requested): self
    {
        return new self("Placement edge [{$edge}] is already in matrix slot {$slot}, so it cannot take slot {$requested}: a matrix slot is assigned once and never moves.");
    }

    public static function missingEdge(string $edge): self
    {
        return new self("Placement edge [{$edge}] does not exist; only a stored placement can be adopted.");
    }

    public static function outsideManager(): self
    {
        return new self('Matrix placement positions are written only by PandaBear\Mlm\Matrix\MatrixPlacementManager.');
    }
}
