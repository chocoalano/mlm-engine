<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Enums\FilterType;

final class SelectFilter extends Filter
{
    /** @var array<array-key, string> */
    private array $options = [];

    private ?string $placeholder = null;

    public function type(): FilterType
    {
        return FilterType::Select;
    }

    /**
     * Keyed by the value the query uses, labelled by what the reader sees.
     *
     * `array-key` rather than `string`, matching every other options-taking
     * component in this package. An id-keyed map is the common case — the
     * filter's own `sanitize()`, `describe()` and `extraArray()` are all
     * written for it — and PHP coerces a decimal-integer string key back to an
     * integer, so `array<string, string>` was a shape no caller could actually
     * hand over. Declaring it made a correctly-typed `pluck('name', 'id')`
     * fail static analysis in the consuming application.
     *
     * @param  array<array-key, string>  $options
     */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /**
     * Only a declared option key is accepted. Anything else, including a
     * value that would be a valid column value, is rejected: the declared
     * options are the whitelist.
     */
    public function sanitize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = (string) $value;

        return array_key_exists($value, $this->options) ? $value : null;
    }

    protected function constrain(Builder $query, mixed $value): void
    {
        $query->where($this->getColumn(), '=', $value);
    }

    /**
     * The chip says the option's label, not its key.
     *
     * `sanitize()` returns the key, and the inherited `describe()` prints a
     * scalar as it stands — so a filter offering "Published" in the dropdown
     * produced a chip reading `Status: published`, or worse `Status: 3` for
     * an id-keyed option. The key is the query's vocabulary and the label is
     * the reader's; only this class holds both.
     */
    protected function describe(mixed $value): string
    {
        $key = is_scalar($value) ? (string) $value : '';

        return $this->options[$key] ?? $key;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'options' => array_map(
                static fn (string $label, string $value): array => ['value' => $value, 'label' => $label],
                array_values($this->options),
                array_map(strval(...), array_keys($this->options)),
            ),
            'placeholder' => $this->placeholder,
        ];
    }
}
