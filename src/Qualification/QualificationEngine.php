<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Qualification;

use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;
use PandaBear\Mlm\Exceptions\QualificationEvaluationException;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Metrics\MetricValue;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleCombinator;
use PandaBear\Mlm\Planning\Rules\RuleGroup;
use PandaBear\Mlm\Planning\Rules\RuleOperator;
use Throwable;

/**
 * Evaluates one stored plan rule, chosen by the caller, for one member over
 * one optional effective range, and explains the result condition by
 * condition (ADR-015).
 *
 * - The rule, its component, version, plan and program, and the member are
 *   all re-read: an instance changed in memory changes nothing.
 * - A draft's rule is refused; a rule of any validated version — validated,
 *   published, active, superseded, archived — can be evaluated, so an old
 *   decision can be reproduced from its own version.
 * - The member must belong to the rule's program.
 * - Every condition's metric is resolved through the `MetricEngine`, with
 *   the condition's parameters and the context's range, and compared exactly.
 * - Every node is evaluated: no group stops at its first deciding child, so
 *   the trace is complete.
 *
 * A request that cannot be evaluated — a draft, another program, a missing
 * or unreadable record, a metric that cannot be resolved — throws
 * `QualificationEvaluationException`. `qualified` is false only when the
 * rule was evaluated and does not hold.
 *
 * Reads only. It chooses no plan, version or rule, stores nothing, and
 * knows nothing of ranks or commission.
 */
final readonly class QualificationEngine
{
    public function __construct(private MetricEngine $metrics) {}

    /**
     * @throws QualificationEvaluationException
     */
    public function evaluate(PlanRule $rule, QualificationContext $context): QualificationDecision
    {
        $connection = $rule->getConnectionName();

        $rule = PlanRule::on($connection)->find($rule->getKey()) ?? throw QualificationEvaluationException::missing('plan rule', (string) $rule->getKey());
        $component = PlanComponent::on($connection)->find($rule->plan_component_id) ?? throw QualificationEvaluationException::missing('plan component', $rule->plan_component_id);
        $version = PlanVersion::on($connection)->find($component->plan_version_id) ?? throw QualificationEvaluationException::missing('plan version', $component->plan_version_id);
        $plan = Plan::on($connection)->find($version->plan_id) ?? throw QualificationEvaluationException::missing('plan', $version->plan_id);
        $program = Program::on($connection)->find($plan->program_id) ?? throw QualificationEvaluationException::missing('program', $plan->program_id);
        $member = Member::on($connection)->find($context->member->getKey()) ?? throw QualificationEvaluationException::missing('member', (string) $context->member->getKey());

        $where = sprintf('plan version [%s] (version %d), component "%s", rule "%s"', $version->getKey(), $version->version, $component->key, $rule->key);

        if ($version->status === PlanVersionStatus::Draft) {
            throw QualificationEvaluationException::draft($where);
        }

        if ($member->program_id !== $plan->program_id) {
            throw QualificationEvaluationException::otherProgram($where, $member->getKey(), $member->program_id, $plan->program_id);
        }

        try {
            $definition = $rule->definition;
        } catch (InvalidRuleDefinition $exception) {
            throw QualificationEvaluationException::unreadableRule($where, $exception);
        }

        $trace = $this->group($definition->root, 'root', $member, $context, $where);

        return new QualificationDecision(
            qualified: $trace->passed,
            programId: $program->getKey(),
            programCode: $program->code,
            planId: $plan->getKey(),
            planCode: $plan->code,
            planVersionId: $version->getKey(),
            planVersion: $version->version,
            componentKey: $component->key,
            ruleKey: $rule->key,
            memberId: $member->getKey(),
            memberCode: $member->member_code,
            from: $context->from,
            until: $context->until,
            trace: $trace,
        );
    }

    private function group(RuleGroup $group, string $path, Member $member, QualificationContext $context, string $where): QualificationGroupTrace
    {
        // Every child, always — never cut short at the first child that
        // decides the group — so the trace explains every condition.
        $children = [];

        foreach ($group->children as $index => $child) {
            $at = "{$path}.children[{$index}]";

            $children[] = $child instanceof MetricCondition
                ? $this->condition($child, $at, $member, $context, $where)
                : $this->group($child, $at, $member, $context, $where);
        }

        $outcomes = array_map(static fn (QualificationTraceNode $child): bool => $child->passed(), $children);

        $passed = match ($group->match) {
            RuleCombinator::All => ! in_array(false, $outcomes, true),
            RuleCombinator::Any => in_array(true, $outcomes, true),
        };

        return new QualificationGroupTrace($path, $group->match, $passed, $children);
    }

    private function condition(MetricCondition $condition, string $path, Member $member, QualificationContext $context, string $where): QualificationConditionTrace
    {
        // Resolved on its own, every time: two conditions naming the same
        // metric and parameters are two resolutions.
        try {
            $value = $this->metrics->resolve(
                $condition->metric,
                new MetricContext($member, $condition->parameters, $context->from, $context->until),
            );
        } catch (Throwable $exception) {
            throw QualificationEvaluationException::metricFailed("{$where}, {$path}, metric \"{$condition->metric}\"", $exception);
        }

        return new QualificationConditionTrace(
            $path,
            $condition->metric,
            $condition->parameters,
            $value,
            $condition->operator,
            $condition->operands,
            $this->holds($condition->operator, $value, $condition->operands),
        );
    }

    /**
     * @param  list<string>  $operands
     */
    private function holds(RuleOperator $operator, MetricValue $value, array $operands): bool
    {
        $against = static fn (string $operand): int => $value->compare(MetricValue::of($operand));

        return match ($operator) {
            RuleOperator::NotEqual => $against($operands[0]) !== 0,
            RuleOperator::GreaterThan => $against($operands[0]) > 0,
            RuleOperator::GreaterThanOrEqual => $against($operands[0]) >= 0,
            RuleOperator::LessThan => $against($operands[0]) < 0,
            RuleOperator::LessThanOrEqual => $against($operands[0]) <= 0,
            RuleOperator::In => in_array(0, array_map($against, $operands), true),
            RuleOperator::NotIn => ! in_array(0, array_map($against, $operands), true),
            // Both bounds included.
            RuleOperator::Between => $against($operands[0]) >= 0 && $against($operands[1]) <= 0,
        };
    }
}
