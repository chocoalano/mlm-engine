<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Illuminate\Validation\Rule;
use PandaPanel\Forms\Enums\FieldType;

/**
 * One choice from a handful, all of them visible.
 *
 * The same data a select holds, shown differently: a radio group trades
 * space for the ability to read every option without opening anything. The
 * declared options are the whitelist, exactly as they are for a select.
 */
final class Radio extends Field
{
    /** @var array<array-key, string> */
    private array $options = [];

    /** @var array<array-key, string> */
    private array $descriptions = [];

    private bool $inline = false;

    public function type(): FieldType
    {
        return FieldType::Radio;
    }

    /**
     * @param  array<array-key, string>  $options
     */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /**
     * A line under each option, for choices that need explaining.
     *
     * @param  array<array-key, string>  $descriptions
     */
    public function descriptions(array $descriptions): self
    {
        $this->descriptions = $descriptions;

        return $this;
    }

    public function inline(bool $inline = true): self
    {
        $this->inline = $inline;

        return $this;
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        return $this->options === []
            ? []
            : [Rule::in(array_map(strval(...), array_keys($this->options)))];
    }

    protected function castForForm(mixed $value): string|int|null
    {
        return is_string($value) || is_int($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        $options = [];

        foreach ($this->options as $value => $label) {
            $options[] = [
                'value' => (string) $value,
                'label' => $label,
                'description' => $this->descriptions[$value] ?? null,
            ];
        }

        return ['options' => $options, 'inline' => $this->inline];
    }
}
