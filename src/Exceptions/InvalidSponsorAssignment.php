<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\Member;

final class InvalidSponsorAssignment extends DomainException
{
    public static function selfSponsorship(Member $member): self
    {
        return new self("Member [{$member->getKey()}] cannot sponsor itself.");
    }

    public static function differentPrograms(Member $member, Member $sponsor): self
    {
        return new self(sprintf(
            'Member [%s] in program [%s] cannot be sponsored by member [%s] in program [%s]: sponsorship stays within one program.',
            $member->getKey(),
            $member->program_id,
            $sponsor->getKey(),
            $sponsor->program_id,
        ));
    }

    public static function alreadySponsored(Member $member, string $currentSponsorId, Member $sponsor): self
    {
        return new self(sprintf(
            'Member [%s] is already sponsored by member [%s] and cannot also be sponsored by [%s]; a sponsor is assigned once.',
            $member->getKey(),
            $currentSponsorId,
            $sponsor->getKey(),
        ));
    }

    public static function cycle(Member $member, Member $sponsor): self
    {
        return new self(sprintf(
            'Member [%s] is among the sponsor descendants of member [%s], so it cannot sponsor it: that would make a cycle.',
            $sponsor->getKey(),
            $member->getKey(),
        ));
    }

    public static function outsideGenealogy(): self
    {
        return new self('Sponsor edges are written only by PandaBear\Mlm\Genealogy\SponsorGenealogy::assignSponsor().');
    }
}
