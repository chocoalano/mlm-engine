<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Period;

use DateTimeInterface;
use LogicException;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Exceptions\CorruptCommissionPeriod;
use PandaBear\Mlm\Exceptions\InvalidCommissionPeriodTransition;
use PandaBear\Mlm\Finance\FinanceInput;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionPeriod;

/**
 * Releases a finalized commission period at a moment the caller gives, no
 * earlier than its release moment (ADR-029): every held commission becomes
 * available — HELD → AVAILABLE, all or none, stamped with that moment.
 * Cancelled ones stay cancelled.
 *
 * Available means eligible to post, not posted: nothing touches the ledger
 * or a wallet, and each commission is posted by `CommissionPoster`, one at
 * a time, when the application chooses. Releasing a released period
 * returns it unchanged.
 */
final readonly class CommissionPeriodReleaser
{
    private const CHUNK = 500;

    /**
     * @throws InvalidCommissionPeriodTransition
     * @throws CorruptCommissionPeriod
     */
    public function release(CommissionPeriod $period, DateTimeInterface $at): CommissionPeriod
    {
        $at = FinanceInput::moment($at);
        $db = $period->getConnection();

        return $db->transaction(static function () use ($db, $period, $at): CommissionPeriod {
            $current = CommissionPeriod::on((string) $db->getName())->whereKey($period->getKey())->lockForUpdate()->firstOrFail();
            $commissions = CommissionPeriodFinalizer::commissions($db, $current);

            if ($current->status === CommissionPeriodStatus::Released) {
                if ($commissions->contains(static fn (Commission $commission): bool => in_array($commission->status, [CommissionStatus::Approved, CommissionStatus::Held], true))) {
                    throw CorruptCommissionPeriod::because((string) $current->getKey(), 'it is released, yet it still has approved or held commissions');
                }

                return $current;
            }

            if ($current->status !== CommissionPeriodStatus::Finalized) {
                throw InvalidCommissionPeriodTransition::status((string) $current->getKey(), $current->status->value, 'released', 'it is finalized');
            }

            if ($at->lessThan($current->release_at)) {
                throw InvalidCommissionPeriodTransition::early((string) $current->getKey(), $at->format('Y-m-d H:i:s'), $current->release_at->format('Y-m-d H:i:s'));
            }

            if ($commissions->contains(static fn (Commission $commission): bool => $commission->status === CommissionStatus::Approved)) {
                throw CorruptCommissionPeriod::because((string) $current->getKey(), 'it is finalized, yet it still has approved commissions');
            }

            $held = $commissions->filter(static fn (Commission $commission): bool => $commission->status === CommissionStatus::Held)->modelKeys();
            $now = $current->freshTimestamp();
            $released = 0;

            foreach (array_chunk($held, self::CHUNK) as $chunk) {
                $released += $db->table('mlm_commissions')->whereIn('id', $chunk)->where('status', CommissionStatus::Held->value)->update([
                    'status' => CommissionStatus::Available->value,
                    'available_at' => $at,
                    'updated_at' => $now,
                ]);
            }

            if ($released !== count($held)) {
                throw new LogicException("Commission period [{$current->getKey()}] changed while it was locked.");
            }

            $db->table('mlm_commission_periods')->where('id', $current->getKey())->where('status', CommissionPeriodStatus::Finalized->value)->update([
                'status' => CommissionPeriodStatus::Released->value,
                'released_at' => $at,
                'updated_at' => $now,
            ]);

            return CommissionPeriod::on((string) $db->getName())->findOrFail($current->getKey());
        });
    }
}
