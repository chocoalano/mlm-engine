<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A rule definition outside the safe rule language: a node, field, operator
 * or operand it does not allow, or a tree beyond its size limits.
 */
final class InvalidRuleDefinition extends DomainException
{
    /**
     * @param  string  $at  where in the tree, e.g. "root.children[1].operands"
     */
    public static function at(string $at, string $reason): self
    {
        return new self("Invalid rule definition at {$at}: {$reason}");
    }
}
