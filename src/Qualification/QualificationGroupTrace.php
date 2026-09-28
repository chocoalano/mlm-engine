<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Qualification;

use PandaBear\Mlm\Planning\Rules\RuleCombinator;

/**
 * How a group evaluated: every child, whatever the earlier ones gave.
 */
final readonly class QualificationGroupTrace implements QualificationTraceNode
{
    /**
     * @param  list<QualificationTraceNode>  $children
     */
    public function __construct(
        public string $path,
        public RuleCombinator $match,
        public bool $passed,
        public array $children,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function toArray(): array
    {
        return [
            'type' => 'group',
            'path' => $this->path,
            'match' => $this->match->value,
            'passed' => $this->passed,
            'children' => array_map(static fn (QualificationTraceNode $child): array => $child->toArray(), $this->children),
        ];
    }
}
