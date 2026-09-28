<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Rank;

use PandaBear\Mlm\Qualification\QualificationGroupTrace;

/**
 * How one rank of the ladder evaluated: the rank, whether the member
 * qualifies for it, and the qualification trace that says why.
 */
final readonly class RankCandidateDecision
{
    public function __construct(
        public string $key,
        public string $name,
        public int $position,
        public bool $qualified,
        public QualificationGroupTrace $trace,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'position' => $this->position,
            'qualified' => $this->qualified,
            'trace' => $this->trace->toArray(),
        ];
    }
}
