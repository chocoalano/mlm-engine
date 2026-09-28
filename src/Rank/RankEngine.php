<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Rank;

use Illuminate\Database\Eloquent\Collection;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\QualificationEvaluationException;
use PandaBear\Mlm\Exceptions\RankEvaluationException;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Qualification\QualificationContext;
use PandaBear\Mlm\Qualification\QualificationEngine;

/**
 * Evaluates one stored rank ladder, chosen by the caller, for one member over
 * one optional effective range (ADR-016): which rank does the member reach?
 *
 * - The component, its version, plan and program, its ranks and the member
 *   are all re-read: an instance changed in memory changes nothing.
 * - The component must be a "rank.ladder"; a draft's ladder is refused; a
 *   ladder of any validated version — validated, published, active,
 *   superseded, archived — can be evaluated.
 * - The member must belong to the ladder's program.
 * - Every rank is qualified through the `QualificationEngine`, lowest
 *   position first, each over the same range — all of them, whatever the
 *   earlier ones gave. Ranks are independent: a higher rank does not need the
 *   lower ones.
 * - The selected rank is the qualifying rank with the highest position, or
 *   null when none qualifies.
 *
 * A request that cannot be evaluated — a wrong driver, a draft, another
 * program, a missing record, a stored ladder validation would refuse, a rank
 * whose qualification cannot be evaluated — throws `RankEvaluationException`
 * and gives no rank at all: a technical failure never reads as a lower rank,
 * nor as none.
 *
 * Reads only. It chooses no plan, version or ladder, stores nothing, and
 * resolves no metric itself.
 */
final readonly class RankEngine
{
    public function __construct(private QualificationEngine $qualification) {}

    /**
     * @throws RankEvaluationException
     */
    public function evaluate(PlanComponent $ladder, RankContext $context): RankDecision
    {
        $connection = $ladder->getConnectionName();

        $component = PlanComponent::on($connection)->find($ladder->getKey()) ?? throw RankEvaluationException::missing('plan component', (string) $ladder->getKey());
        $version = PlanVersion::on($connection)->find($component->plan_version_id) ?? throw RankEvaluationException::missing('plan version', $component->plan_version_id);
        $plan = Plan::on($connection)->find($version->plan_id) ?? throw RankEvaluationException::missing('plan', $version->plan_id);
        $program = Program::on($connection)->find($plan->program_id) ?? throw RankEvaluationException::missing('program', $plan->program_id);
        $member = Member::on($connection)->find($context->member->getKey()) ?? throw RankEvaluationException::missing('member', (string) $context->member->getKey());

        $where = sprintf('plan version [%s] (version %d), component "%s"', $version->getKey(), $version->version, $component->key);

        if ($component->driver !== RankLadderDriver::KEY) {
            throw RankEvaluationException::notRankLadder($where, $component->driver);
        }

        if ($version->status === PlanVersionStatus::Draft) {
            throw RankEvaluationException::draft($where);
        }

        if ($member->program_id !== $plan->program_id) {
            throw RankEvaluationException::otherProgram($where, $member->getKey(), $member->program_id, $plan->program_id);
        }

        $ranks = PlanRule::on($connection)->where('plan_component_id', $component->getKey())->orderBy('position')->orderBy('id')->get();

        $this->assertLadder($where, $component, $ranks);

        // Every rank, always — never cut short once one qualifies or fails —
        // so the decision explains the whole ladder.
        $qualification = new QualificationContext($member, $context->from, $context->until);
        $candidates = [];

        foreach ($ranks as $rank) {
            $candidates[] = $this->candidate($where, $rank, $qualification);
        }

        return new RankDecision(
            selectedRank: $this->select($candidates),
            programId: $program->getKey(),
            programCode: $program->code,
            planId: $plan->getKey(),
            planCode: $plan->code,
            planVersionId: $version->getKey(),
            planVersion: $version->version,
            componentKey: $component->key,
            componentName: $component->name,
            memberId: $member->getKey(),
            memberCode: $member->member_code,
            from: $context->from,
            until: $context->until,
            ranks: $candidates,
        );
    }

    /**
     * Holds the stored ladder to the rules its version was validated under.
     * Only raw writes break them; an ambiguous ladder is refused, never
     * answered.
     *
     * @param  Collection<int, PlanRule>  $ranks
     */
    private function assertLadder(string $where, PlanComponent $component, Collection $ranks): void
    {
        try {
            $parameters = $component->parameters;
        } catch (InvalidPlanDefinition $exception) {
            throw RankEvaluationException::invalidLadder($where, $exception->getMessage(), $exception);
        }

        $problem = RankLadderDriver::problem(
            $component->driver,
            $parameters,
            $ranks->map(static fn (PlanRule $rank): array => [$rank->key, $rank->position])->values()->all(),
        );

        if ($problem !== null) {
            throw RankEvaluationException::invalidLadder($where, $problem);
        }
    }

    private function candidate(string $where, PlanRule $rank, QualificationContext $context): RankCandidateDecision
    {
        try {
            $decision = $this->qualification->evaluate($rank, $context);
        } catch (QualificationEvaluationException $exception) {
            throw RankEvaluationException::rankFailed($where, $rank->key, $rank->position, $exception);
        }

        return new RankCandidateDecision($rank->key, $rank->name, $rank->position, $decision->qualified, $decision->trace);
    }

    /**
     * The qualifying rank with the highest position — whether or not the
     * ranks below it qualify — or null.
     *
     * @param  list<RankCandidateDecision>  $candidates
     */
    private function select(array $candidates): ?SelectedRank
    {
        $selected = null;

        foreach ($candidates as $candidate) {
            if ($candidate->qualified && ($selected === null || $candidate->position > $selected->position)) {
                $selected = $candidate;
            }
        }

        return $selected === null ? null : new SelectedRank($selected->key, $selected->name, $selected->position);
    }
}
