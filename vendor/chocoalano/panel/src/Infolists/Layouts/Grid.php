<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Layouts;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Infolists\Components\Entry;
use PandaPanel\Infolists\Components\InfolistComponent;
use PandaPanel\Support\ColumnCount;

/**
 * Columns without a heading.
 *
 * A `Section` says "these belong together and here is what they are". A grid
 * says only "these sit side by side", which is what you want inside a section
 * that already has a title.
 */
final class Grid extends InfolistComponent
{
    /** @var list<InfolistComponent> */
    private array $components = [];

    public function __construct(private readonly int $columns = 2) {}

    public static function make(int $columns = 2): self
    {
        return new self(ColumnCount::clamp($columns));
    }

    /**
     * @param  array<array-key, InfolistComponent>  $components
     */
    public function schema(array $components): self
    {
        $this->components = array_values($components);

        return $this;
    }

    /**
     * @return list<Entry>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->components as $component) {
            $entries = [...$entries, ...$component->entries()];
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toArray(Model $record): ?array
    {
        $schema = [];

        foreach ($this->components as $component) {
            $child = $component->toArray($record);

            if ($child !== null) {
                $schema[] = $child;
            }
        }

        if ($schema === []) {
            return null;
        }

        return [
            'component' => 'grid',
            'columns' => $this->columns,
            'schema' => $schema,
        ];
    }
}
