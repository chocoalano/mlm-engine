<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Illuminate\Validation\Rule;
use PandaPanel\Forms\Enums\FieldType;
use PandaPanel\Support\ColumnCount;

/**
 * Several choices from a list, all of them visible.
 *
 * The value is an array, so it validates in two parts: the field itself must
 * be an array, and each element must be one of the declared keys. Laravel
 * will not infer the second from a rule list on the first, which is why
 * `elementRules()` exists separately.
 */
final class CheckboxList extends Field
{
    /** @var array<array-key, string> */
    private array $options = [];

    /** @var array<array-key, string> */
    private array $descriptions = [];

    private int $columns = 1;

    private bool $bulkToggleable = false;

    public function type(): FieldType
    {
        return FieldType::CheckboxList;
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
     * @param  array<array-key, string>  $descriptions
     */
    public function descriptions(array $descriptions): self
    {
        $this->descriptions = $descriptions;

        return $this;
    }

    public function columns(int $columns): self
    {
        $this->columns = ColumnCount::clamp($columns);

        return $this;
    }

    /**
     * Offers "select all" and "clear", for a list long enough that clicking
     * each one is the tedious part.
     */
    public function bulkToggleable(bool $bulkToggleable = true): self
    {
        $this->bulkToggleable = $bulkToggleable;

        return $this;
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        return ['array'];
    }

    /**
     * The rules for each element, which Laravel expects under `field.*`.
     *
     * @return list<mixed>
     */
    public function elementRules(): array
    {
        return $this->options === []
            ? []
            : [Rule::in(array_map(strval(...), array_keys($this->options)))];
    }

    /**
     * @return list<string>
     */
    protected function castForForm(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_map(strval(...), $value))
            : [];
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

        return [
            'options' => $options,
            'columns' => $this->columns,
            'bulkToggleable' => $this->bulkToggleable,
        ];
    }
}
