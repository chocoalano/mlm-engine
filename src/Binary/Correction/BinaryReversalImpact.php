<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Correction;

/**
 * What a volume reversal reaches in binary pairing state (ADR-024): every
 * carry lot its original created, in every component and binary member's
 * leg, and whether paired quantity — correctable only explicitly — depends
 * on any of them. Inert data: it changes nothing and knows no models.
 */
final readonly class BinaryReversalImpact
{
    /**
     * @param  list<BinaryReversalLotImpact>  $lots  by component, member, side, then lot
     */
    public function __construct(
        public string $programId,
        public string $originalEntryId,
        public string $reversalEntryId,
        public string $reversalEffectiveAt,
        public array $lots,
    ) {}

    /**
     * How many lots, or how many in one state.
     */
    public function count(?BinaryReversalLotState $state = null): int
    {
        return count($state === null ? $this->lots : array_filter($this->lots, static fn (BinaryReversalLotImpact $lot): bool => $lot->state === $state));
    }

    /**
     * Whether any lot was partly or wholly paired: then the reversal cannot
     * simply take its carry back.
     */
    public function requiresConsumedCorrection(): bool
    {
        foreach ($this->lots as $lot) {
            if ($lot->state->isConsumed()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lots of one pairing component.
     *
     * @return list<BinaryReversalLotImpact>
     */
    public function forComponent(string $planComponentId): array
    {
        return array_values(array_filter($this->lots, static fn (BinaryReversalLotImpact $lot): bool => $lot->planComponentId === $planComponentId));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'program_id' => $this->programId,
            'original_volume_entry_id' => $this->originalEntryId,
            'reversal_volume_entry_id' => $this->reversalEntryId,
            'reversal_effective_at' => $this->reversalEffectiveAt,
            'lot_count' => $this->count(),
            'unconsumed' => $this->count(BinaryReversalLotState::Unconsumed),
            'partially_consumed' => $this->count(BinaryReversalLotState::PartiallyConsumed),
            'fully_consumed' => $this->count(BinaryReversalLotState::FullyConsumed),
            'already_removed' => $this->count(BinaryReversalLotState::AlreadyRemoved),
            'requires_consumed_correction' => $this->requiresConsumedCorrection(),
            'lots' => array_map(static fn (BinaryReversalLotImpact $lot): array => $lot->toArray(), $this->lots),
        ];
    }
}
