<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Layouts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;
use PandaPanel\Support\ColumnCount;

/**
 * One tab of a form.
 *
 * Reports the field names it holds for the same reason a wizard step does:
 * the frontend has to be able to open the tab containing a rejected field
 * without knowing how the tab is laid out.
 */
final class Tab extends FormComponent
{
    /** @var list<FormComponent> */
    private array $components = [];

    private ?string $icon = null;

    private ?string $badge = null;

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

    /**
     * An icon registry key, never a path.
     */
    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function badge(string $badge): self
    {
        $this->badge = $badge;

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
            'component' => 'tab',
            'label' => $this->label,
            'key' => Str::slug($this->label),
            'icon' => $this->icon,
            'badge' => $this->badge,
            'columns' => $this->columns,
            'schema' => $schema,
            // The names in this tab, so the frontend can open the one holding
            // a field the server rejected without knowing the layout.
            'fields' => array_map(
                static fn (Field $field): string => $field->getName(),
                $this->fields(),
            ),
        ];
    }
}
