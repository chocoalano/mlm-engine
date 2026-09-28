<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Tables\Enums\FilterType;

/**
 * A filter with a form behind it rather than a single control.
 *
 * For a question that takes more than one answer: a range with two bounds, a
 * status *and* an owner, a date plus a tolerance. The form is an ordinary
 * `FormSchema`, so the fields render, validate, and serialize exactly as they
 * do on a resource form — there is one definition of what a select is, not a
 * second one for filters.
 *
 * The value that reaches `query()` is the *validated* form data. A key the
 * schema does not declare is discarded before the closure ever sees it, which
 * is the same guarantee a resource form gives: the schema is the whitelist,
 * and the query closure can trust its input.
 */
final class FormFilter extends Filter
{
    /** @var (Closure(FormSchema): FormSchema)|null */
    private ?Closure $formUsing = null;

    public function type(): FilterType
    {
        return FilterType::Form;
    }

    /**
     * @param  Closure(FormSchema): FormSchema  $callback
     */
    public function form(Closure $callback): self
    {
        $this->formUsing = $callback;

        return $this;
    }

    public function schema(): FormSchema
    {
        $schema = FormSchema::make();

        return $this->formUsing === null ? $schema : ($this->formUsing)($schema);
    }

    /**
     * The validated form data, or null when nothing was actually filled in.
     *
     * Null rather than an array of empties, because `Filter::apply()` reads
     * null as "this filter has nothing to say" — a form whose every field is
     * blank should narrow nothing rather than adding constraints on empty
     * strings.
     *
     * @return array<string, mixed>|null
     */
    public function sanitize(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $schema = $this->schema();

        $validator = validator($value, $schema->validationRules());

        if ($validator->fails()) {
            return null;
        }

        $validated = $validator->validated();

        $filled = array_filter(
            $validated,
            static fn (mixed $entry): bool => $entry !== null && $entry !== '' && $entry !== [],
        );

        return $filled === [] ? null : $validated;
    }

    /**
     * A form filter has no default constraint to fall back on: what its
     * fields mean is the schema author's knowledge, not this class's.
     */
    protected function constrain(Builder $query, mixed $value): void {}

    protected function describe(mixed $value): string
    {
        if (! is_array($value)) {
            return '';
        }

        $parts = [];

        foreach ($this->schema()->fields() as $field) {
            $entry = $value[$field->getName()] ?? null;

            if ($entry === null || $entry === '' || $entry === []) {
                continue;
            }

            $parts[] = $field->getLabel().' '.(is_scalar($entry) ? (string) $entry : '…');
        }

        return implode(', ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return ['form' => $this->schema()->toArray()];
    }
}
