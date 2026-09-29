<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Fixtures;

use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionCandidate;
use PandaBear\Mlm\Commission\CommissionStrategy;
use PandaBear\Mlm\Commission\CommissionStrategyDefinition;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Qualification\QualificationContext;
use PandaBear\Mlm\Qualification\QualificationEngine;

/**
 * A strategy composed from the package's read services: every member who
 * qualifies under the component's own "eligible" rule, over the run's
 * range, earns the fixed `amount`; the qualification trace is kept.
 */
final readonly class QualifiedCommissionStrategy implements CommissionStrategy
{
    public function __construct(private QualificationEngine $qualification) {}

    public function key(): string
    {
        return 'test.qualified';
    }

    public function validate(CommissionStrategyDefinition $definition): void
    {
        if ($definition->rule('eligible') === null || array_keys($definition->parameters) !== ['amount']) {
            throw InvalidPlanDefinition::input('qualified strategy', 'it needs an "eligible" rule and an "amount".');
        }
    }

    public function calculate(CommissionCalculationContext $context): iterable
    {
        $component = PlanComponent::on($context->connection)
            ->where('plan_version_id', $context->definition->planVersionId)
            ->where('key', $context->definition->componentKey)
            ->sole();
        $rule = PlanRule::on($context->connection)->where('plan_component_id', $component->id)->where('key', 'eligible')->sole();

        foreach (Member::on($context->connection)->where('program_id', $context->program->id)->orderBy('member_code')->get() as $member) {
            $decision = $this->qualification->evaluate($rule, new QualificationContext($member, $context->from, $context->until));

            if ($decision->qualified) {
                yield new CommissionCandidate(
                    key: "qualified:{$member->member_code}",
                    member: $member,
                    amount: $context->definition->parameters['amount'],
                    earnedAt: $context->until->subSecond(),
                    trace: ['qualification' => $decision->trace->toArray()],
                );
            }
        }
    }
}
