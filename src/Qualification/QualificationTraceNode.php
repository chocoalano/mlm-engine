<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Qualification;

/**
 * One node of an evaluation trace — a group or a condition — mirroring the
 * rule's own tree, at the same path ("root", "root.children[1]").
 */
interface QualificationTraceNode
{
    public function path(): string;

    public function passed(): bool;

    /**
     * Plain data only: strings, integers, booleans, null and arrays.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
