<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Layouts;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;
use PandaPanel\Support\ColumnCount;

/**
 * One step of a wizard.
 *
 * Layout only, like every other form layout: which step a field sits in
 * cannot change what the server validates or persists. That is what lets the
 * wizard be presentation and nothing more.
 */
final class Step extends FormComponent
{
    /** @var list<FormComponent> */
    private array $components = [];

    private ?string $description = null;

    private ?string $icon = null;

    private int $columns = 1;

    public function __construct(private readonly string $label) {}

    public static function make(string $label): self
    {
        return new self($label);
    }

    /**
     * @param  array<array-key, FormComponent>  $components
     */
    public function schema(array $components): self
    {
        $this->components = array_values($components);

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * An icon registry key, never a component path.
     */
    public function icon(?string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function columns(int $columns): self
    {
        $this->columns = ColumnCount::clamp($columns);

        return $this;
    }

    /**
     * @return list<FormComponent>
     */
    public function children(): array
    {
        return $this->components;
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->components as $component) {
            $fields = [...$fields, ...$component->fields()];
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Model $record, string $page): array
    {
        $schema = [];

        foreach ($this->components as $component) {
            $child = $component->toArray($record, $page);

            if ($child !== null) {
                $schema[] = $child;
            }
        }

        return [
            'component' => 'step',
            'label' => $this->label,
            'description' => $this->description,
            'icon' => $this->icon,
            'columns' => $this->columns,
            'schema' => $schema,
            // The names in this step, so the frontend can jump to the first
            // step holding a field the server rejected without knowing how
            // the step is laid out.
            'fields' => array_map(
                static fn (Field $field): string => $field->getName(),
                $this->fields(),
            ),
        ];
    }
}
