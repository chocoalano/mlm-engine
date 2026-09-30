<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Period;

use DateTimeInterface;
use Illuminate\Database\Connection;
use PandaBear\Mlm\Exceptions\FinalizedCommissionPeriod;
use PandaBear\Mlm\Support\EffectiveMoment;

/**
 * Keeps new business entries out of a commission period whose input is
 * closed (ADR-029): one whose calculation has begun, and every calculated,
 * finalized or released one — not only finalized ones, whatever the name:
 * a calculated period's commissions were calculated without the entry.
 *
 * It judges the new entry's own moment: an entry, or a reversal, at a
 * moment of an open period or outside every period is recorded, even when
 * it reverses activity of a closed period — the correction belongs to the
 * later range. Entries already stored are never touched.
 *
 * Called by `VolumeRecorder` inside the transaction that inserts the entry.
 * The period row is read under a shared lock, held until that insert
 * commits; a period's calculation closes its input under an exclusive lock
 * of the same row. So an entry either commits before the input closes —
 * and is calculated — or sees it closed and is refused: never recorded,
 * uncounted, in a calculated range.
 */
final readonly class FinalizedCommissionPeriodGuard
{
    /**
     * @throws FinalizedCommissionPeriod
     */
    public function assertAccepts(Connection $db, string $programId, DateTimeInterface $at): void
    {
        $at = EffectiveMoment::of($at);

        $period = $db->table('mlm_commission_periods')
            ->where('program_id', $programId)
            ->where('from_at', '<=', $at)
            ->where('until_at', '>', $at)
            ->sharedLock()
            ->first(['id', 'status', 'input_closed_at', 'from_at', 'until_at']);

        if ($period === null || ($period->status === CommissionPeriodStatus::Open->value && $period->input_closed_at === null)) {
            return;
        }

        $status = $period->status === CommissionPeriodStatus::Open->value ? 'being calculated' : (string) $period->status;

        throw FinalizedCommissionPeriod::inputClosed((string) $period->id, $status, $at->format('Y-m-d H:i:s'), (string) $period->from_at, (string) $period->until_at);
    }
}
