<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Enums\FilterType;

/**
 * Three states rather than two: yes, no, and no opinion.
 *
 * A boolean filter has the same three states — its "all" is the absence of a
 * value — but a ternary says so out loud, which matters when the third state
 * is a real answer the table means rather than a control that happens to be
 * empty. `TrashedFilter` is the archetype: "hidden" is a decision, not a
 * cleared filter.
 *
 * Each branch can own its query, so the two answers need not be inverses:
 * "has a manager" and "has no manager" are `whereHas` and `whereDoesntHave`,
 * not one constraint and its negation.
 */
final class TernaryFilter extends Filter
{
    public const TRUE = 'true';

    public const FALSE = 'false';

    private string $trueLabel = 'Yes';

    private string $falseLabel = 'No';

    private string $blankLabel = 'All';

    private bool $nullable = false;

    /** @var (Closure(Builder<covariant \Illuminate\Database\Eloquent\Model>): void)|null */
    private ?Closure $trueQuery = null;

    /** @var (Closure(Builder<covariant \Illuminate\Database\Eloquent\Model>): void)|null */
    private ?Closure $falseQuery = null;

    public function type(): FilterType
    {
        return FilterType::Ternary;
    }

    public function labels(string $true, string $false, ?string $blank = null): self
    {
        $this->trueLabel = $true;
        $this->falseLabel = $false;
        $this->blankLabel = $blank ?? $this->blankLabel;

        return $this;
    }

    /**
     * Treats the column as set versus not set, so a nullable timestamp such
     * as `email_verified_at` reads as a yes-or-no without a second column.
     */
    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * @param  Closure(Builder<covariant \Illuminate\Database\Eloquent\Model>): void  $true
     * @param  Closure(Builder<covariant \Illuminate\Database\Eloquent\Model>): void  $false
     */
    public function queries(Closure $true, Closure $false): self
    {
        $this->trueQuery = $true;
        $this->falseQuery = $false;

        return $this;
    }

    /**
     * `1` and `0` are accepted as synonyms for the two answers.
     *
     * A ternary usually sits over a boolean column, so those are exactly what
     * arrives from a bookmarked URL or a hand-written link. Refusing them
     * would silently drop the filter — a worse failure than accepting an
     * obvious synonym, and the set stays closed either way.
     */
    public function sanitize(mixed $value): ?string
    {
        return match (true) {
            $value === self::TRUE, $value === '1', $value === 1, $value === true => self::TRUE,
            $value === self::FALSE, $value === '0', $value === 0, $value === false => self::FALSE,
            default => null,
        };
    }

    protected function constrain(Builder $query, mixed $value): void
    {
        $isTrue = $value === self::TRUE;

        if ($this->trueQuery !== null && $this->falseQuery !== null) {
            $isTrue ? ($this->trueQuery)($query) : ($this->falseQuery)($query);

            return;
        }

        $column = $this->getColumn();

        if ($this->nullable) {
            $isTrue ? $query->whereNotNull($column) : $query->whereNull($column);

            return;
        }

        $query->where($column, '=', $isTrue);
    }

    protected function describe(mixed $value): string
    {
        return $value === self::TRUE ? $this->trueLabel : $this->falseLabel;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'blankLabel' => $this->blankLabel,
            'options' => [
                ['value' => self::TRUE, 'label' => $this->trueLabel],
                ['value' => self::FALSE, 'label' => $this->falseLabel],
            ],
        ];
    }
}
