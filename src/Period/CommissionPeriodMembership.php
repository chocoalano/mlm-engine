<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Period;

use Illuminate\Database\Connection;
use PandaBear\Mlm\Models\CommissionPeriod;

/**
 * @internal
 *
 * Which commission period a calculation run belongs to (ADR-029): the one
 * it is linked to — or, before its link is written, the one whose derived
 * key it was calculated under, directly (`period:<period>:<component>`) or
 * through the period's hybrid batch (`hybrid:<batch>:<component>`, the
 * batch keyed `period:<period>:hybrid`). A run a crash left unlinked is
 * the period's all the same, and is never posted as if it stood alone.
 */
final class CommissionPeriodMembership
{
    private const ULID = '[0-9a-z]{26}';

    public static function periodOfRun(Connection $db, string $runId): ?CommissionPeriod
    {
        $connection = (string) $db->getName();
        $linked = $db->table('mlm_commission_period_runs')->where('calculation_run_id', $runId)->value('commission_period_id');

        if ($linked !== null) {
            return CommissionPeriod::on($connection)->find($linked);
        }

        $run = $db->table('mlm_calculation_runs')->where('id', $runId)->first(['program_id', 'idempotency_key']);

        if ($run === null) {
            return null;
        }

        $period = null;

        if (preg_match('/^period:('.self::ULID.'):/', (string) $run->idempotency_key, $match) === 1) {
            $period = $match[1];
        } elseif (preg_match('/^hybrid:('.self::ULID.'):/', (string) $run->idempotency_key, $match) === 1) {
            $batchKey = $db->table('mlm_calculation_batches')->where('id', $match[1])->where('program_id', $run->program_id)->value('idempotency_key');

            if (is_string($batchKey) && preg_match('/^period:('.self::ULID.'):hybrid$/', $batchKey, $batch) === 1) {
                $period = $batch[1];
            }
        }

        return $period === null ? null : CommissionPeriod::on($connection)->where('id', $period)->where('program_id', $run->program_id)->first();
    }

    public static function childKey(string $period, string $component): string
    {
        return "period:{$period}:{$component}";
    }

    public static function hybridKey(string $period): string
    {
        return "period:{$period}:hybrid";
    }
}
