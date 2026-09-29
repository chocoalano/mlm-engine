<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Fixtures;

use Closure;
use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionCandidate;
use PandaBear\Mlm\Commission\CommissionStrategy;
use PandaBear\Mlm\Commission\CommissionStrategyDefinition;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Models\Member;

/**
 * Reads the program twice — its members, and each one's sales volume
 * through the metric engine — with `$between` run in the middle, and records
 * both readings in every candidate's trace. Inside one calculation both
 * readings must be the same, whatever `$between` lets another session
 * commit.
 */
final readonly class SnapshotProbeStrategy implements CommissionStrategy
{
    /**
     * @param  Closure(): void  $between
     */
    public function __construct(private MetricEngine $metrics, private Closure $between) {}

    public function key(): string
    {
        return 'test.snapshot';
    }

    public function validate(CommissionStrategyDefinition $definition): void {}

    public function calculate(CommissionCalculationContext $context): iterable
    {
        $first = $this->read($context);
        ($this->between)();
        $second = $this->read($context);

        foreach (array_keys($first['volume']) as $code) {
            yield new CommissionCandidate(
                key: "member:{$code}",
                member: Member::on($context->connection)->where('program_id', $context->program->id)->where('member_code', $code)->sole(),
                amount: '1',
                earnedAt: $context->until->subSecond(),
                trace: ['first' => $first, 'second' => $second],
            );
        }
    }

    /**
     * @return array{members: int, volume: array<string, string>}
     */
    private function read(CommissionCalculationContext $context): array
    {
        $members = Member::on($context->connection)->where('program_id', $context->program->id)->orderBy('member_code')->get();
        $volume = [];

        foreach ($members as $member) {
            $volume[$member->member_code] = $this->metrics->resolve('member.volume', new MetricContext($member, ['type' => 'sales'], $context->from, $context->until))->value();
        }

        return ['members' => $members->count(), 'volume' => $volume];
    }
}
