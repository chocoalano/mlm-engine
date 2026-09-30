<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Period;

use DateTimeInterface;
use PandaBear\Mlm\Commission\CommissionComponentDriver;
use PandaBear\Mlm\Commission\CommissionFunding;
use PandaBear\Mlm\Exceptions\ConflictingCommissionPeriod;
use PandaBear\Mlm\Exceptions\InvalidCommissionPeriod;
use PandaBear\Mlm\Finance\FinanceInput;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Planning\PlanVersionStatus;

/**
 * Creates commission periods (ADR-029) — the only way one is written.
 *
 * A period is one program's calculation, review and release boundary: the
 * program's active plan version when it is created — kept, whatever
 * becomes active later — one range [from, until), a release moment no
 * earlier than its end, and one source account funding every commission
 * component of the version. A program's periods never overlap; adjacent
 * ones, and gaps, are fine. Everything is read from the database, never
 * from the instances passed in.
 *
 * Creation is idempotent under the caller's key within the program: the
 * same facts return the period, other facts are refused. It runs under the
 * program's lock, so two creations of one program — the same key or
 * overlapping ranges — never both succeed.
 */
final readonly class CommissionPeriodManager
{
    /**
     * @throws InvalidCommissionPeriod
     * @throws ConflictingCommissionPeriod
     */
    public function create(
        Program $program,
        PlanVersion $planVersion,
        LedgerAccount $sourceAccount,
        DateTimeInterface $from,
        DateTimeInterface $until,
        DateTimeInterface $releaseAt,
        string $idempotencyKey,
    ): CommissionPeriod {
        $from = FinanceInput::moment($from);
        $until = FinanceInput::moment($until);
        $releaseAt = FinanceInput::moment($releaseAt);

        if (! $from->lessThan($until)) {
            throw InvalidCommissionPeriod::range($from->format('Y-m-d H:i:s'), $until->format('Y-m-d H:i:s'));
        }

        if ($releaseAt->lessThan($until)) {
            throw InvalidCommissionPeriod::releaseBeforeEnd($releaseAt->format('Y-m-d H:i:s'), $until->format('Y-m-d H:i:s'));
        }

        if (! FinanceInput::isText($idempotencyKey, FinanceInput::IDEMPOTENCY_KEY_LENGTH)) {
            throw InvalidCommissionPeriod::idempotencyKey($idempotencyKey);
        }

        $db = $program->getConnection();
        $connection = (string) $db->getName();
        $versionId = (string) $planVersion->getKey();
        $accountId = (string) $sourceAccount->getKey();

        return $db->transaction(static function () use ($db, $connection, $program, $versionId, $accountId, $from, $until, $releaseAt, $idempotencyKey): CommissionPeriod {
            // The program's lock first: every period write of the program,
            // and every genealogy write, waits on it.
            $program = Program::on($connection)->whereKey($program->getKey())->lockForUpdate()->firstOrFail();

            $existing = CommissionPeriod::on($connection)
                ->where('program_id', $program->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $conflicts = array_keys(array_diff_assoc([
                    'plan version' => $versionId,
                    'source account' => $accountId,
                    'from' => $from->format('Y-m-d H:i:s'),
                    'until' => $until->format('Y-m-d H:i:s'),
                    'release at' => $releaseAt->format('Y-m-d H:i:s'),
                ], [
                    'plan version' => $existing->plan_version_id,
                    'source account' => $existing->source_ledger_account_id,
                    'from' => $existing->from_at->format('Y-m-d H:i:s'),
                    'until' => $existing->until_at->format('Y-m-d H:i:s'),
                    'release at' => $existing->release_at->format('Y-m-d H:i:s'),
                ]));

                if ($conflicts !== []) {
                    throw ConflictingCommissionPeriod::forKey($existing, $conflicts);
                }

                return $existing;
            }

            $version = PlanVersion::on($connection)->find($versionId) ?? throw InvalidCommissionPeriod::otherProgram('plan version', $versionId, (string) $program->getKey());

            if (Plan::on($connection)->whereKey($version->plan_id)->value('program_id') !== $program->getKey()) {
                throw InvalidCommissionPeriod::otherProgram('plan version', $versionId, (string) $program->getKey());
            }

            if ($version->status !== PlanVersionStatus::Active) {
                throw InvalidCommissionPeriod::inactiveVersion($versionId, $version->status->value);
            }

            $components = PlanComponent::on($connection)->where('plan_version_id', $versionId)->where('driver', CommissionComponentDriver::KEY)->get();
            $problem = CommissionFunding::problem($connection, (string) $program->getKey(), $accountId, $components);

            if ($problem !== null) {
                throw InvalidCommissionPeriod::funding($accountId, $problem);
            }

            $overlapping = CommissionPeriod::on($connection)
                ->where('program_id', $program->getKey())
                ->where('from_at', '<', $until)
                ->where('until_at', '>', $from)
                ->value('id');

            if ($overlapping !== null) {
                throw InvalidCommissionPeriod::overlaps((string) $program->getKey(), $from->format('Y-m-d H:i:s'), $until->format('Y-m-d H:i:s'), (string) $overlapping);
            }

            $period = new CommissionPeriod;
            $id = $period->newUniqueId();
            $now = $period->freshTimestamp();

            $db->table('mlm_commission_periods')->insert([
                'id' => $id,
                'program_id' => $program->getKey(),
                'plan_version_id' => $versionId,
                'source_ledger_account_id' => $accountId,
                'idempotency_key' => $idempotencyKey,
                'from_at' => $from,
                'until_at' => $until,
                'release_at' => $releaseAt,
                'status' => CommissionPeriodStatus::Open->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return CommissionPeriod::on($connection)->findOrFail($id);
        });
    }
}
