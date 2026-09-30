<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Period;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Commission\UnresolvedBinaryCorrections;
use PandaBear\Mlm\Exceptions\InvalidCommissionPeriodTransition;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionPeriod;

/**
 * Finalizes a calculated commission period (ADR-029): its review is over,
 * and every approved commission is held — APPROVED → HELD, all or none.
 *
 * Every commission of the period must be approved or cancelled, and none
 * may have a binary correction whose financial share is not yet recorded
 * — the same reading posting refuses on. A reversal after the period's end
 * belongs to later corrections, and does not stop it. Nothing touches the
 * ledger or a wallet.
 *
 * One transaction: the period, then its commissions in id order, are
 * locked, everything is checked again under the locks, and the commissions
 * and the period move together. Finalizing a finalized or released period
 * returns it as it is.
 */
final readonly class CommissionPeriodFinalizer
{
    private const CHUNK = 500;

    /**
     * @throws InvalidCommissionPeriodTransition
     */
    public function finalize(CommissionPeriod $period): CommissionPeriod
    {
        $db = $period->getConnection();

        return $db->transaction(static function () use ($db, $period): CommissionPeriod {
            $current = CommissionPeriod::on((string) $db->getName())->whereKey($period->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($current->status, [CommissionPeriodStatus::Finalized, CommissionPeriodStatus::Released], true)) {
                return $current;
            }

            if ($current->status !== CommissionPeriodStatus::Calculated) {
                throw InvalidCommissionPeriodTransition::status((string) $current->getKey(), $current->status->value, 'finalized', 'it is calculated');
            }

            $commissions = self::commissions($db, $current);
            $unreviewed = $commissions
                ->reject(static fn (Commission $commission): bool => in_array($commission->status, [CommissionStatus::Approved, CommissionStatus::Cancelled], true))
                ->countBy(static fn (Commission $commission): string => $commission->status->value)
                ->sortKeys()
                ->all();

            if ($unreviewed !== []) {
                throw InvalidCommissionPeriodTransition::unreviewed((string) $current->getKey(), $unreviewed);
            }

            $approved = $commissions->filter(static fn (Commission $commission): bool => $commission->status === CommissionStatus::Approved)->modelKeys();
            $unresolved = UnresolvedBinaryCorrections::of($db, $approved);

            if ($unresolved !== []) {
                throw InvalidCommissionPeriodTransition::unresolvedBinaryCorrections((string) $current->getKey(), array_keys($unresolved));
            }

            $now = $current->freshTimestamp();
            $held = 0;

            foreach (array_chunk($approved, self::CHUNK) as $chunk) {
                $held += $db->table('mlm_commissions')->whereIn('id', $chunk)->where('status', CommissionStatus::Approved->value)->update([
                    'status' => CommissionStatus::Held->value,
                    'held_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($held !== count($approved)) {
                throw new LogicException("Commission period [{$current->getKey()}] changed while it was locked.");
            }

            $db->table('mlm_commission_periods')->where('id', $current->getKey())->where('status', CommissionPeriodStatus::Calculated->value)->update([
                'status' => CommissionPeriodStatus::Finalized->value,
                'finalized_at' => $now,
                'updated_at' => $now,
            ]);

            return CommissionPeriod::on((string) $db->getName())->findOrFail($current->getKey());
        });
    }

    /**
     * The period's commissions — those of its linked runs — locked in id
     * order.
     *
     * @return Collection<int, Commission>
     */
    public static function commissions(Connection $db, CommissionPeriod $period): Collection
    {
        return Commission::on((string) $db->getName())
            ->whereIn('calculation_run_id', $db->table('mlm_commission_period_runs')->select('calculation_run_id')->where('commission_period_id', $period->getKey()))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
