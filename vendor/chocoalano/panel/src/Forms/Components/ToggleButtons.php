<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Illuminate\Validation\Rule;
use PandaPanel\Forms\Enums\FieldType;
use PandaPanel\Tables\Enums\BadgeColor;

/**
 * A row of buttons where one, or several, stay pressed.
 *
 * A radio group that reads as a segmented control. Colours reuse the badge
 * palette rather than a second vocabulary, so a status is the same colour
 * wherever it appears.
 */
final class ToggleButtons extends Field
{
    /** @var array<array-key, string> */
    private array $options = [];

    /** @var array<array-key, BadgeColor> */
    private array $colors = [];

    /** @var array<array-key, string> */
    private array $icons = [];

    private bool $multiple = false;

    private bool $inline = true;

    public function type(): FieldType
    {
        return FieldType::ToggleButtons;
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
     * @param  array<array-key, BadgeColor>  $colors
     */
    public function colors(array $colors): self
    {
        $this->colors = $colors;

        return $this;
    }

    /**
     * Icon registry keys, so a button can never ask for one the build did not
     * compile in.
     *
     * @param  array<array-key, string>  $icons
     */
    public function icons(array $icons): self
    {
        $this->icons = $icons;

        return $this;
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function inline(bool $inline = true): self
    {
        $this->inline = $inline;

        return $this;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        if ($this->multiple) {
            return ['array'];
        }

        return $this->options === []
            ? []
            : [Rule::in(array_map(strval(...), array_keys($this->options)))];
    }

    /**
     * @return list<mixed>
     */
    public function elementRules(): array
    {
        if (! $this->multiple || $this->options === []) {
            return [];
        }

        return [Rule::in(array_map(strval(...), array_keys($this->options)))];
    }

    /**
     * @return string|int|list<string>|null
     */
    protected function castForForm(mixed $value): string|int|array|null
    {
        if ($this->multiple) {
            return is_array($value) ? array_values(array_map(strval(...), $value)) : [];
        }

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
                'color' => ($this->colors[$value] ?? BadgeColor::Neutral)->value,
                'icon' => $this->icons[$value] ?? null,
            ];
        }

        return [
            'options' => $options,
            'multiple' => $this->multiple,
            'inline' => $this->inline,
        ];
    }
}
