<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Fixtures;

use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionCandidate;
use PandaBear\Mlm\Commission\CommissionStrategy;
use PandaBear\Mlm\Commission\CommissionStrategyDefinition;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Models\Member;

/**
 * A stand-in for a real compensation strategy: every member of the program
 * earns the component's fixed `amount`, keyed by member code, at the last
 * second of the range. It remembers how often it calculated and which
 * definitions it judged.
 */
final class FixedCommissionStrategy implements CommissionStrategy
{
    public int $calculations = 0;

    /**
     * @var list<CommissionStrategyDefinition>
     */
    public array $validated = [];

    public function key(): string
    {
        return 'test.fixed';
    }

    public function validate(CommissionStrategyDefinition $definition): void
    {
        $this->validated[] = $definition;
        $amount = $definition->parameters['amount'] ?? null;

        if (array_keys($definition->parameters) !== ['amount'] || ! is_string($amount) || preg_match('/^\d+(\.\d{1,6})?$/D', $amount) !== 1 || FinancialAmount::of($amount)->isZero()) {
            throw InvalidPlanDefinition::input('parameter "amount"', 'the fixed strategy pays a positive decimal "amount", and takes nothing else.');
        }
    }

    public function calculate(CommissionCalculationContext $context): iterable
    {
        $this->calculations++;
        $amount = $context->definition->parameters['amount'];

        foreach (Member::on($context->connection)->where('program_id', $context->program->id)->orderBy('member_code')->get() as $member) {
            yield new CommissionCandidate(
                key: "member:{$member->member_code}",
                member: $member,
                amount: $amount,
                earnedAt: $context->until->subSecond(),
                trace: [
                    'strategy' => 'test.fixed',
                    'member_code' => $member->member_code,
                    'amount' => $amount,
                    'rules' => array_map(static fn ($rule): string => $rule->key, $context->definition->rules),
                    'range' => ['from' => $context->from->format('Y-m-d H:i:s'), 'until' => $context->until->format('Y-m-d H:i:s')],
                ],
            );
        }
    }
}
