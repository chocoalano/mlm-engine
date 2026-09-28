<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\Member;

final class InvalidPlacementAssignment extends DomainException
{
    public static function selfPlacement(Member $member): self
    {
        return new self("Member [{$member->getKey()}] cannot be placed under itself.");
    }

    public static function differentPrograms(Member $member, Member $parent): self
    {
        return new self(sprintf(
            'Member [%s] in program [%s] cannot be placed under member [%s] in program [%s]: placement stays within one program.',
            $member->getKey(),
            $member->program_id,
            $parent->getKey(),
            $parent->program_id,
        ));
    }

    public static function alreadyPlaced(Member $member, string $currentParentId, Member $parent): self
    {
        return new self(sprintf(
            'Member [%s] is already placed under member [%s] and cannot also be placed under [%s]; a placement is made once.',
            $member->getKey(),
            $currentParentId,
            $parent->getKey(),
        ));
    }

    public static function cycle(Member $member, Member $parent): self
    {
        return new self(sprintf(
            'Member [%1$s] is among the placement descendants of member [%2$s], so [%2$s] cannot be placed under [%1$s]: that would make a cycle.',
            $parent->getKey(),
            $member->getKey(),
        ));
    }

    public static function outsideGenealogy(): self
    {
        return new self('Placement edges are written only by PandaBear\Mlm\Genealogy\PlacementGenealogy::place().');
    }
}
