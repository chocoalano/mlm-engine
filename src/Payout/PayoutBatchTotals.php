<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Payout;

use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Models\PayoutBatch;

/**
 * A payout batch's money, exactly (ADR-030), in one aggregate query by
 * request status:
 *
 * - `requested`: every request's amount;
 * - `reserved`: what is still reserved or paid out — approved, processing
 *   and settled requests;
 * - `settled` and `failed`: settled and failed requests, with counts.
 *
 * No float; nothing is written.
 */
final readonly class PayoutBatchTotals
{
    private function __construct(
        public int $count,
        public FinancialAmount $requested,
        public FinancialAmount $reserved,
        public int $settledCount,
        public FinancialAmount $settled,
        public int $failedCount,
        public FinancialAmount $failed,
    ) {}

    public static function of(PayoutBatch $batch): self
    {
        $rows = $batch->getConnection()->table('mlm_payout_batch_items as items')
            ->join('mlm_payout_requests as requests', 'requests.id', '=', 'items.payout_request_id')
            ->where('items.payout_batch_id', $batch->getKey())
            ->groupBy('requests.status')
            ->selectRaw('requests.status as status, COUNT(*) as requests, SUM(requests.amount_millionths) as amount')
            ->get()
            ->keyBy('status');

        $amount = static fn (?object $row): FinancialAmount => FinancialAmount::fromMillionths((string) ($row->amount ?? 0));
        $count = static fn (?object $row): int => (int) ($row->requests ?? 0);
        $requested = $reserved = FinancialAmount::zero();

        foreach ($rows as $status => $row) {
            $requested = $requested->add($amount($row));

            if (PayoutRequestStatus::from((string) $status)->holdsReservation()) {
                $reserved = $reserved->add($amount($row));
            }
        }

        $settled = $rows->get(PayoutRequestStatus::Settled->value);
        $failed = $rows->get(PayoutRequestStatus::Failed->value);

        return new self((int) $rows->sum('requests'), $requested, $reserved, $count($settled), $amount($settled), $count($failed), $amount($failed));
    }
}
