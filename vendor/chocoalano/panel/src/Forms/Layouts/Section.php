<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Layouts;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;
use PandaPanel\Support\ColumnCount;

/**
 * A titled group of fields.
 *
 * Layout only. It never affects validation or persistence, so moving a field
 * between sections cannot change what the server accepts.
 */
final class Section extends FormComponent
{
    /** @var list<FormComponent> */
    private array $components = [];

    private ?string $description = null;

    private int $columns = 1;

    private bool $collapsible = false;

    public function __construct(private readonly string $heading) {}

    public static function make(string $heading): self
    {
        return new self($heading);
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

    public function columns(int $columns): self
    {
        $this->columns = ColumnCount::clamp($columns);

        return $this;
    }

    public function collapsible(bool $collapsible = true): self
    {
        $this->collapsible = $collapsible;

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
     * A container always renders; only a field can disappear on a page.
     *
     * @return array<string, mixed>
     */
    public function toArray(?Model $record, string $page): array
    {
        return [
            'component' => 'section',
            'heading' => $this->heading,
            'description' => $this->description,
            'columns' => $this->columns,
            'collapsible' => $this->collapsible,
            'schema' => $this->serializeChildren($record, $page),
        ];
    }

    /**
     * Children that are hidden on this page drop out entirely, so an empty
     * container renders as an empty container rather than as gaps.
     *
     * @return list<array<string, mixed>>
     */
    private function serializeChildren(?Model $record, string $page): array
    {
        $serialized = [];

        foreach ($this->components as $component) {
            $child = $component->toArray($record, $page);

            if ($child !== null) {
                $serialized[] = $child;
            }
        }

        return $serialized;
    }
}
