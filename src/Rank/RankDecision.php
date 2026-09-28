<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Rank;

use Carbon\CarbonImmutable;

/**
 * The outcome of evaluating one rank ladder for one member over one range:
 * the rank selected — the qualifying rank with the highest position, or null
 * when no rank qualifies — and every rank of the ladder, lowest first, with
 * its qualification trace. The ladder is named by the identities of its
 * program, plan, version and component.
 *
 * Plain values only, no models, and no evaluation time: the same stored data
 * and context give the same decision, and the same `toArray()`.
 */
final readonly class RankDecision
{
    /**
     * @param  list<RankCandidateDecision>  $ranks  in ladder order, lowest position first
     */
    public function __construct(
        public ?SelectedRank $selectedRank,
        public string $programId,
        public string $programCode,
        public string $planId,
        public string $planCode,
        public string $planVersionId,
        public int $planVersion,
        public string $componentKey,
        public string $componentName,
        public string $memberId,
        public string $memberCode,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $until,
        public array $ranks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'program' => ['id' => $this->programId, 'code' => $this->programCode],
            'plan' => ['id' => $this->planId, 'code' => $this->planCode],
            'plan_version' => ['id' => $this->planVersionId, 'version' => $this->planVersion],
            'component' => ['key' => $this->componentKey, 'name' => $this->componentName],
            'member' => ['id' => $this->memberId, 'member_code' => $this->memberCode],
            'from' => $this->from?->format('Y-m-d H:i:s'),
            'until' => $this->until?->format('Y-m-d H:i:s'),
            'selected_rank' => $this->selectedRank?->toArray(),
            'ranks' => array_map(static fn (RankCandidateDecision $rank): array => $rank->toArray(), $this->ranks),
        ];
    }
}
