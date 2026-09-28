<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Qualification;

use PandaBear\Mlm\Metrics\MetricValue;
use PandaBear\Mlm\Planning\Rules\RuleOperator;

/**
 * How a condition evaluated: the metric and parameters it named, the value
 * the metric resolved to, and how that value compared with the operands.
 */
final readonly class QualificationConditionTrace implements QualificationTraceNode
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  list<string>  $operands  canonical decimal strings
     */
    public function __construct(
        public string $path,
        public string $metric,
        public array $parameters,
        public MetricValue $value,
        public RuleOperator $operator,
        public array $operands,
        public bool $passed,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function toArray(): array
    {
        return [
            'type' => 'condition',
            'path' => $this->path,
            'metric' => $this->metric,
            'parameters' => $this->parameters,
            'value' => $this->value->value(),
            'operator' => $this->operator->value,
            'operands' => $this->operands,
            'passed' => $this->passed,
        ];
    }
}
