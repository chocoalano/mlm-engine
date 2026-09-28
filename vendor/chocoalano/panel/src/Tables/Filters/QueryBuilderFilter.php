<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Enums\ConstraintOperator;
use PandaPanel\Tables\Enums\FilterType;
use PandaPanel\Tables\Filters\Constraints\Constraint;

/**
 * A filter the user composes: a list of rules, each naming a column, an
 * operator, and a value.
 *
 * Everything about a rule is checked against a declaration before it reaches
 * the query. The column must be one the filter declared as a `Constraint`,
 * the operator must be one that constraint supports, and the value must be
 * one that constraint accepts. Nothing is concatenated from the request: the
 * column string comes from the `Constraint` object and the comparison from a
 * closed enum. A rule that fails any of those checks is dropped, not
 * repaired — a query the user did not describe is worse than no rule at all.
 *
 * Rules are ANDed. Nested groups with mixed and/or are deliberately not here:
 * they need a recursive schema on both sides and a UI to match, and a flat
 * list of conditions answers the question most tables are actually asked.
 * Reach for `FormFilter` when the shape is known, and for a custom `query()`
 * when it is not.
 */
final class QueryBuilderFilter extends Filter
{
    /** @var list<Constraint> */
    private array $constraints = [];

    /**
     * Constraints derived from the table's own columns.
     *
     * Kept apart from the declared ones so precedence is a property of the
     * data rather than of the order somebody happened to call things in: a
     * constraint the developer wrote always wins over one this package
     * inferred from a column type. `TableSchema` fills this in at
     * serialization time, because a filter does not know which table it is
     * attached to and should not have to.
     *
     * @var list<Constraint>
     */
    private array $derived = [];

    /** So one filter cannot become an unbounded pile of joins. */
    private int $maxRules = 10;

    public function type(): FilterType
    {
        return FilterType::QueryBuilder;
    }

    /**
     * @param  array<array-key, Constraint>  $constraints
     */
    public function constraints(array $constraints): self
    {
        $this->constraints = array_values($constraints);

        return $this;
    }

    /**
     * Offers the table's columns as conditions.
     *
     * A column the table already lists is a thing the reader can see, and
     * asking them to declare it a second time as a `Constraint` to filter by
     * it is asking them to keep two lists in step. The columns are the list;
     * this reads it.
     *
     * @param  array<array-key, Constraint>  $constraints
     */
    public function derivedConstraints(array $constraints): self
    {
        $this->derived = array_values($constraints);

        return $this;
    }

    /**
     * Every constraint this filter offers, declared ones first.
     *
     * A declared constraint replaces a derived one of the same name outright.
     * That is the whole compatibility story for tables written before columns
     * could describe themselves: they keep the constraints they declared, and
     * gain the columns they did not.
     *
     * @return list<Constraint>
     */
    public function allConstraints(): array
    {
        $declared = [];

        foreach ($this->constraints as $constraint) {
            $declared[$constraint->getName()] = $constraint;
        }

        $merged = $this->constraints;

        foreach ($this->derived as $constraint) {
            if (! array_key_exists($constraint->getName(), $declared)) {
                $merged[] = $constraint;
            }
        }

        return $merged;
    }

    public function maxRules(int $max): self
    {
        $this->maxRules = max(1, $max);

        return $this;
    }

    /**
     * Looks a constraint up by name, derived ones included.
     *
     * This is the gate every incoming rule passes through — a column the
     * filter cannot name here is a rule that never reaches the query. It has
     * to see the derived constraints or a condition on an ordinary column
     * would be silently dropped after the user built it.
     */
    public function constraint(string $name): ?Constraint
    {
        foreach ($this->allConstraints() as $constraint) {
            if ($constraint->getName() === $name) {
                return $constraint;
            }
        }

        return null;
    }

    /**
     * The rules that survive validation, in order.
     *
     * @return list<array{constraint: Constraint, operator: ConstraintOperator, value: mixed}>|null
     */
    public function sanitize(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $rules = [];

        foreach ($value as $rule) {
            if (count($rules) >= $this->maxRules) {
                break;
            }

            $parsed = $this->parseRule($rule);

            if ($parsed !== null) {
                $rules[] = $parsed;
            }
        }

        return $rules === [] ? null : $rules;
    }

    /**
     * @return array{constraint: Constraint, operator: ConstraintOperator, value: mixed}|null
     */
    private function parseRule(mixed $rule): ?array
    {
        if (! is_array($rule)) {
            return null;
        }

        $column = $rule['column'] ?? null;
        $operator = $rule['operator'] ?? null;

        if (! is_string($column) || ! is_string($operator)) {
            return null;
        }

        // The column must be one this filter declared. A name that was not
        // declared does not exist, however the request spells it.
        $constraint = $this->constraint($column);

        if ($constraint === null) {
            return null;
        }

        $resolved = ConstraintOperator::tryFrom($operator);

        // And the operator must be one that constraint supports: a `contains`
        // on a boolean is not a comparison this table offered.
        if ($resolved === null || ! $constraint->supports($resolved)) {
            return null;
        }

        $value = $rule['value'] ?? null;

        if (! $constraint->accepts($resolved, $value)) {
            return null;
        }

        return ['constraint' => $constraint, 'operator' => $resolved, 'value' => $value];
    }

    protected function constrain(Builder $query, mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        // Grouped, so a rule can never widen a search or another filter that
        // was already applied.
        $query->where(static function (Builder $group) use ($value): void {
            foreach ($value as $rule) {
                $rule['constraint']->apply($group, $rule['operator'], $rule['value']);
            }
        });
    }

    protected function describe(mixed $value): string
    {
        if (! is_array($value)) {
            return '';
        }

        $parts = [];

        foreach ($value as $rule) {
            $parts[] = trim(sprintf(
                '%s %s %s',
                $rule['constraint']->getLabel(),
                $rule['operator']->label(),
                $rule['operator']->needsValue() && is_scalar($rule['value'])
                    ? (string) $rule['value']
                    : '',
            ));
        }

        return implode(' and ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'constraints' => array_map(
                static fn (Constraint $constraint): array => $constraint->toArray(),
                $this->allConstraints(),
            ),
            'maxRules' => $this->maxRules,
        ];
    }
}
