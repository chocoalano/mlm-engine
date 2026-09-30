<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Period;

use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Models\CommissionPeriod;

/**
 * A commission period's money, exactly (ADR-029), read in two aggregate
 * queries — by status, over the commissions of its linked runs:
 *
 * - `calculated`: every commission's amount as calculated;
 * - `adjustments`: every adjustment recorded against them (zero or less);
 * - `net`: what the commissions still carrying entitlement are worth —
 *   amount plus adjustments, cancelled and reversed ones left out;
 * - `posted`: what posting moved to wallets for commissions now posted.
 *
 * Counts by status come with it. No float; nothing is written.
 */
final readonly class CommissionPeriodTotals
{
    /**
     * @param  array<string, int>  $counts  commissions by status
     */
    private function __construct(
        public FinancialAmount $calculated,
        public FinancialAmount $adjustments,
        public FinancialAmount $net,
        public FinancialAmount $posted,
        public array $counts,
    ) {}

    public static function of(CommissionPeriod $period): self
    {
        $db = $period->getConnection();
        $runs = $db->table('mlm_commission_period_runs')->select('calculation_run_id')->where('commission_period_id', $period->getKey());
        $amount = static fn (mixed $millionths): FinancialAmount => FinancialAmount::fromMillionths((string) ($millionths ?? 0));

        $byStatus = $db->table('mlm_commissions')
            ->whereIn('calculation_run_id', $runs)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as commissions, SUM(amount_millionths) as amount, SUM(posted_amount_millionths) as posted')
            ->get()
            ->keyBy('status');

        $adjusted = $db->table('mlm_commission_adjustments as adjustments')
            ->join('mlm_commissions as commissions', 'commissions.id', '=', 'adjustments.commission_id')
            ->whereIn('commissions.calculation_run_id', $runs)
            ->groupBy('commissions.status')
            ->selectRaw('commissions.status as status, SUM(adjustments.amount_millionths) as amount')
            ->get()
            ->keyBy('status');

        $calculated = $adjustments = $net = $posted = FinancialAmount::zero();
        $counts = [];

        foreach ($byStatus as $status => $row) {
            $counts[(string) $status] = (int) $row->commissions;
            $calculated = $calculated->add($amount($row->amount));
            $adjustment = $amount($adjusted->get($status)?->amount);
            $adjustments = $adjustments->add($adjustment);

            if (! in_array($status, [CommissionStatus::Cancelled->value, CommissionStatus::Reversed->value], true)) {
                $net = $net->add($amount($row->amount))->add($adjustment);
            }

            if ($status === CommissionStatus::Posted->value) {
                $posted = $posted->add($amount($row->posted));
            }
        }

        ksort($counts);

        return new self($calculated, $adjustments, $net, $posted, $counts);
    }
}
