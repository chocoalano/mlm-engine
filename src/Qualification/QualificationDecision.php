<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Qualification;

use Carbon\CarbonImmutable;

/**
 * The outcome of evaluating one rule for one member over one range: whether
 * the member qualifies, which stored rule said so — by the identities of its
 * program, plan, version, component and rule — and the complete trace.
 *
 * Plain values only, no models, and no evaluation time: the same stored data
 * and context give the same decision, and the same `toArray()`.
 */
final readonly class QualificationDecision
{
    public function __construct(
        public bool $qualified,
        public string $programId,
        public string $programCode,
        public string $planId,
        public string $planCode,
        public string $planVersionId,
        public int $planVersion,
        public string $componentKey,
        public string $ruleKey,
        public string $memberId,
        public string $memberCode,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $until,
        public QualificationGroupTrace $trace,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'qualified' => $this->qualified,
            'program' => ['id' => $this->programId, 'code' => $this->programCode],
            'plan' => ['id' => $this->planId, 'code' => $this->planCode],
            'plan_version' => ['id' => $this->planVersionId, 'version' => $this->planVersion],
            'component' => $this->componentKey,
            'rule' => $this->ruleKey,
            'member' => ['id' => $this->memberId, 'member_code' => $this->memberCode],
            'from' => $this->from?->format('Y-m-d H:i:s'),
            'until' => $this->until?->format('Y-m-d H:i:s'),
            'trace' => $this->trace->toArray(),
        ];
    }
}
