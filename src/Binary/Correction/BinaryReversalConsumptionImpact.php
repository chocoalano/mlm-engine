<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Correction;

/**
 * One pairing that consumed part of a reversed entry's carry lot: the
 * allocation, the pairing result and run it belongs to, the binary member
 * who earned, and the commission that pairing produced — none when nothing
 * paired into money, such as a proportional award rounded to zero. What
 * later corrections released of it (ADR-025) — invalidated, or restored to
 * carry — is shown beside what it allocated, and what still counts.
 * Quantities and amounts are exact canonical decimals; moments are
 * `Y-m-d H:i:s`.
 */
final readonly class BinaryReversalConsumptionImpact
{
    public function __construct(
        public string $allocationId,
        public string $side,
        public string $quantity,
        public string $pairingResultId,
        public string $calculationRunId,
        public string $runFrom,
        public string $runUntil,
        public string $earningMemberId,
        public string $pairingConsumedQuantity,
        public ?string $commissionId,
        public ?string $commissionStatus,
        public ?string $commissionAmount,
        public ?string $commissionCurrency,
        public string $invalidatedQuantity = '0',
        public string $restoredQuantity = '0',
        public ?string $netQuantity = null,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'allocation_id' => $this->allocationId,
            'side' => $this->side,
            'quantity' => $this->quantity,
            'pairing_result_id' => $this->pairingResultId,
            'calculation_run_id' => $this->calculationRunId,
            'run_from' => $this->runFrom,
            'run_until' => $this->runUntil,
            'earning_member_id' => $this->earningMemberId,
            'pairing_consumed_quantity' => $this->pairingConsumedQuantity,
            'commission_id' => $this->commissionId,
            'commission_status' => $this->commissionStatus,
            'commission_amount' => $this->commissionAmount,
            'commission_currency' => $this->commissionCurrency,
            'invalidated_quantity' => $this->invalidatedQuantity,
            'restored_quantity' => $this->restoredQuantity,
            'net_quantity' => $this->netQuantity ?? $this->quantity,
        ];
    }
}
