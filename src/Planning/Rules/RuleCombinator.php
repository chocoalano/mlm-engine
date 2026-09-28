<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning\Rules;

/**
 * How a group combines its children: `all` holds when every child holds,
 * `any` when at least one does. Only these two.
 */
enum RuleCombinator: string
{
    case All = 'all';
    case Any = 'any';
}
