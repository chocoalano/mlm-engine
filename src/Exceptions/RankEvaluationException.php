<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use Throwable;

/**
 * A rank ladder could not be evaluated — a request that is invalid, a ladder
 * that is not one, or a rank whose qualification could not be evaluated.
 * Never a member reaching no rank: that is a decision whose selected rank is
 * null.
 *
 * The message names the plan version, component and rank concerned, never
 * parameter values; the underlying failure is kept as the previous exception.
 */
final class RankEvaluationException extends DomainException
{
    public static function missing(string $what, string $id): self
    {
        return new self("Cannot evaluate: the {$what} [{$id}] does not exist.");
    }

    /**
     * @param  string  $where  e.g. "plan version [01…] (version 2), component "career-ranks""
     */
    public static function notRankLadder(string $where, string $driver): self
    {
        return new self(sprintf('Cannot evaluate %s as a rank ladder: its driver is "%s", not "rank.ladder".', $where, $driver));
    }

    public static function draft(string $where): self
    {
        return new self("Cannot evaluate {$where}: its plan version is a draft. Only a validated version — validated, published, active, superseded or archived — can be evaluated.");
    }

    public static function otherProgram(string $where, string $member, string $memberProgram, string $ladderProgram): self
    {
        return new self("Cannot evaluate {$where} for member [{$member}]: the member belongs to program [{$memberProgram}], the rank ladder to program [{$ladderProgram}].");
    }

    /**
     * A stored ladder that validation would refuse — only raw writes produce
     * one.
     */
    public static function invalidLadder(string $where, string $reason, ?Throwable $previous = null): self
    {
        return new self("Cannot evaluate {$where}: it is not a valid rank ladder: {$reason}", previous: $previous);
    }

    public static function rankFailed(string $where, string $rank, int $position, Throwable $previous): self
    {
        return new self(sprintf('Cannot evaluate %s: rank "%s" (position %d) could not be evaluated: %s', $where, $rank, $position, $previous->getMessage()), previous: $previous);
    }
}
