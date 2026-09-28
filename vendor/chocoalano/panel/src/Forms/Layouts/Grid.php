<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Layouts;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;
use PandaPanel\Support\ColumnCount;

/**
 * An untitled column grid.
 *
 * The column count is clamped by `ColumnCount` to what the renderer has
 * literal Tailwind classes for, because an interpolated `grid-cols-{n}` would
 * compile to nothing — and an unclamped count fell through to the renderer's
 * one-column fallback, which is the opposite of what was asked for.
 */
final class Grid extends FormComponent
{
    /** @var list<FormComponent> */
    private array $components = [];

    public function __construct(private readonly int $columns = 2) {}

    public static function make(int $columns = 2): self
    {
        return new self(ColumnCount::clamp($columns));
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
            'component' => 'grid',
            'columns' => $this->columns,
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
