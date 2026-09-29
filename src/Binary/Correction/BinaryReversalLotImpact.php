<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Correction;

/**
 * One carry lot the reversed entry created — in one binary member's leg,
 * for one pairing component — with its exact quantity, what remains, what
 * pairings consumed, and every consumption in order.
 */
final readonly class BinaryReversalLotImpact
{
    /**
     * @param  list<BinaryReversalConsumptionImpact>  $consumptions
     */
    public function __construct(
        public string $lotId,
        public string $planComponentId,
        public string $memberId,
        public string $side,
        public string $originalQuantity,
        public string $remainingQuantity,
        public string $consumedQuantity,
        public BinaryReversalLotState $state,
        public array $consumptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lot_id' => $this->lotId,
            'plan_component_id' => $this->planComponentId,
            'member_id' => $this->memberId,
            'side' => $this->side,
            'original_quantity' => $this->originalQuantity,
            'remaining_quantity' => $this->remainingQuantity,
            'consumed_quantity' => $this->consumedQuantity,
            'state' => $this->state->value,
            'consumptions' => array_map(static fn (BinaryReversalConsumptionImpact $consumption): array => $consumption->toArray(), $this->consumptions),
        ];
    }
}
