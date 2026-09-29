<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Models\Member;

/**
 * A binary placement that cannot be made: the slot is taken, the edge is
 * already on the other side, or it is not a stored placement. Nothing is
 * placed or adopted.
 */
final class InvalidBinaryPlacement extends DomainException
{
    public static function sideOccupied(Member $parent, BinarySide $side, string $occupant): self
    {
        return new self("The {$side->value} of member [{$parent->getKey()}] is already taken by member [{$occupant}]; a parent has one binary child on each side.");
    }

    public static function alreadyOnOtherSide(string $edge, BinarySide $side, BinarySide $requested): self
    {
        return new self("Placement edge [{$edge}] is already binary {$side->value}, so it cannot be {$requested->value}: a binary side is assigned once and never moves.");
    }

    public static function missingEdge(string $edge): self
    {
        return new self("Placement edge [{$edge}] does not exist; only a stored placement can be adopted.");
    }

    public static function outsideManager(): self
    {
        return new self('Binary placement positions are written only by PandaBear\Mlm\Binary\BinaryPlacementManager.');
    }
}
