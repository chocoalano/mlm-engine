<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use Throwable;

/**
 * A rule could not be evaluated — a request that is invalid, or data or
 * configuration that is missing or broken. Never a member failing to
 * qualify: that is a decision whose `qualified` is false.
 *
 * The message names the plan version, component, rule, condition path and
 * metric concerned, never parameter values; the underlying failure is kept
 * as the previous exception.
 */
final class QualificationEvaluationException extends DomainException
{
    public static function missing(string $what, string $id): self
    {
        return new self("Cannot evaluate: the {$what} [{$id}] does not exist.");
    }

    /**
     * @param  string  $where  e.g. "plan version [01…] (version 2), component "entry", rule "active""
     */
    public static function draft(string $where): self
    {
        return new self("Cannot evaluate {$where}: its plan version is a draft. Only a validated version — validated, published, active, superseded or archived — can be evaluated.");
    }

    public static function otherProgram(string $where, string $member, string $memberProgram, string $ruleProgram): self
    {
        return new self("Cannot evaluate {$where} for member [{$member}]: the member belongs to program [{$memberProgram}], the rule to program [{$ruleProgram}].");
    }

    public static function unreadableRule(string $where, Throwable $previous): self
    {
        return new self("Cannot evaluate {$where}: its stored definition cannot be read: {$previous->getMessage()}", previous: $previous);
    }

    public static function metricFailed(string $where, Throwable $previous): self
    {
        return new self("Cannot evaluate {$where}: the metric could not be resolved: {$previous->getMessage()}", previous: $previous);
    }
}
