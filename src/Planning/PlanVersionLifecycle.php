<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning;

use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\InvalidPlanVersionTransition;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanVersion;

/**
 * The only supported way to create plan versions and move them through
 * their lifecycle:
 *
 *   draft → validated → published → active → superseded → archived
 *
 * Superseding is not a separate operation. It happens to the previously
 * active version when a newer one is activated, in the same transaction, so a
 * plan is never left with two active versions — or with none by accident.
 *
 * Each write is a compare-and-set on the status the version is expected to
 * have, made after locking a fresh copy of the row, so a stale model instance
 * can never move a version that has since moved on.
 *
 * A version is validated only if its complete definition is (ADR-014).
 */
final class PlanVersionLifecycle
{
    /**
     * @param  PlanDefinitionValidator|null  $validator  resolved from the container when not given
     */
    public function __construct(private ?PlanDefinitionValidator $validator = null) {}

    /**
     * A new draft, numbered one past the highest version the plan has.
     */
    public function draft(Plan $plan): PlanVersion
    {
        return $plan->getConnection()->transaction(static function () use ($plan): PlanVersion {
            // Two drafts of one plan are numbered one after the other.
            $plan->newQuery()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();

            $version = new PlanVersion;
            $version->forceFill(['version' => (int) $plan->versions()->max('version') + 1]);

            $plan->versions()->save($version);

            return $version;
        });
    }

    /**
     * Validates the draft's complete definition and, if it holds, marks the
     * version validated — in one transaction, under the version row's lock,
     * the lock every definition edit takes. No edit can land between the
     * check and the transition, and a failed check leaves the version a draft
     * with nothing changed.
     *
     * @throws InvalidPlanDefinition
     * @throws InvalidPlanVersionTransition
     */
    public function markValidated(PlanVersion $version): PlanVersion
    {
        $version->getConnection()->transaction(function () use ($version): void {
            $current = $this->lockFresh($version);

            if (! $current->status->canTransitionTo(PlanVersionStatus::Validated)) {
                throw InvalidPlanVersionTransition::notNextStep($current, PlanVersionStatus::Validated);
            }

            $this->validator()->validate($current);

            $this->move($current, PlanVersionStatus::Validated, $current->freshTimestamp());
        });

        return $version->refresh();
    }

    public function publish(PlanVersion $version): PlanVersion
    {
        return $this->advance($version, PlanVersionStatus::Published);
    }

    /**
     * Makes a published version its plan's active version, superseding the
     * one it replaces. Only forward: the version must be newer than the
     * version it replaces.
     *
     * @throws InvalidPlanVersionTransition
     */
    public function activate(PlanVersion $version): PlanVersion
    {
        $version->getConnection()->transaction(function () use ($version): void {
            // Every activation of a plan locks the plan's row first, so two
            // activations of the same plan cannot interleave.
            $version->plan()->lockForUpdate()->firstOrFail();

            $target = $this->lockFresh($version);

            if (! $target->status->canTransitionTo(PlanVersionStatus::Active)) {
                throw InvalidPlanVersionTransition::notNextStep($target, PlanVersionStatus::Active);
            }

            $active = $target->newQuery()
                ->where('plan_id', $target->plan_id)
                ->where('status', PlanVersionStatus::Active)
                ->lockForUpdate()
                ->get();

            $now = $target->freshTimestamp();

            foreach ($active as $current) {
                if ($target->version <= $current->version) {
                    throw InvalidPlanVersionTransition::olderThanActive($target, $current);
                }

                $this->move($current, PlanVersionStatus::Superseded, $now);
            }

            $this->move($target, PlanVersionStatus::Active, $now);
        });

        return $version->refresh();
    }

    /**
     * Only a superseded version can be archived.
     */
    public function archive(PlanVersion $version): PlanVersion
    {
        return $this->advance($version, PlanVersionStatus::Archived);
    }

    private function advance(PlanVersion $version, PlanVersionStatus $to): PlanVersion
    {
        $version->getConnection()->transaction(function () use ($version, $to): void {
            $current = $this->lockFresh($version);

            if (! $current->status->canTransitionTo($to)) {
                throw InvalidPlanVersionTransition::notNextStep($current, $to);
            }

            $this->move($current, $to, $current->freshTimestamp());
        });

        return $version->refresh();
    }

    private function validator(): PlanDefinitionValidator
    {
        return $this->validator ??= Container::getInstance()->make(PlanDefinitionValidator::class);
    }

    private function lockFresh(PlanVersion $version): PlanVersion
    {
        return $version->newQuery()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Query-builder write, deliberately: the model refuses lifecycle changes
     * through a plain save.
     */
    private function move(PlanVersion $version, PlanVersionStatus $to, CarbonInterface $at): void
    {
        $moved = $version->newQuery()
            ->whereKey($version->getKey())
            ->where('status', $version->status)
            ->update([
                'status' => $to,
                PlanVersion::stampColumn($to) => $at,
            ]);

        if ($moved !== 1) {
            throw InvalidPlanVersionTransition::changedConcurrently($version, $version->status);
        }
    }
}
