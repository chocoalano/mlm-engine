<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Support;

use PandaPanel\Forms\Enums\ConditionOperator;

/**
 * One comparison against another field's value.
 *
 * Serializes to scalars and arrays, so the browser can re-evaluate it as the
 * user types without the server having sent any code. The same object also
 * answers on the server, which is what keeps the rendered form and the
 * validation pass from disagreeing about whether a field was even there.
 */
final readonly class Condition
{
    public function __construct(
        public string $field,
        public ConditionOperator $operator,
        public mixed $value = null,
    ) {}

    public static function make(
        string $field,
        ConditionOperator $operator = ConditionOperator::Truthy,
        mixed $value = null,
    ): self {
        return new self($field, $operator, $value);
    }

    /**
     * @param  array<string, mixed>  $state  the form's current values, keyed by field name
     */
    public function matches(array $state): bool
    {
        return $this->operator->matches($state[$this->field] ?? null, $this->value);
    }

    /**
     * @return array{field: string, operator: string, value: mixed}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'operator' => $this->operator->value,
            'value' => $this->operator->needsValue() ? $this->value : null,
        ];
    }
}
