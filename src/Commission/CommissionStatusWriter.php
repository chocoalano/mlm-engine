<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use LogicException;
use PandaBear\Mlm\Models\Commission;

/**
 * @internal
 *
 * Moves a locked commission to its next status, stamping the moment and any
 * ledger reference. A query-builder write, deliberately: the model refuses
 * writes. Guarded by the status it was read with, so a write can never move
 * a commission that another has moved.
 */
final class CommissionStatusWriter
{
    /**
     * @param  array<string, string>  $columns  ledger references to record with the move
     */
    public static function move(Connection $db, Commission $current, CommissionStatus $to, CarbonInterface $at, array $columns = []): Commission
    {
        $moved = $db->table('mlm_commissions')
            ->where('id', $current->getKey())
            ->where('status', $current->status->value)
            ->update([
                'status' => $to->value,
                (string) $to->stampColumn() => $at,
                ...$columns,
                'updated_at' => $at,
            ]);

        if ($moved !== 1) {
            throw new LogicException("Commission [{$current->getKey()}] changed while it was locked.");
        }

        return Commission::on($db->getName())->findOrFail($current->getKey());
    }

    /**
     * The stored commission, locked for the rest of the transaction.
     */
    public static function lock(Connection $db, Commission $commission): Commission
    {
        return Commission::on($db->getName())->whereKey($commission->getKey())->lockForUpdate()->firstOrFail();
    }
}
