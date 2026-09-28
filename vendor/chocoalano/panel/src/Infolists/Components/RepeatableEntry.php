<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Components;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PandaPanel\Infolists\Enums\EntryType;
use PandaPanel\Infolists\Support\InfolistRow;
use PandaPanel\Support\ColumnCount;

/**
 * One sub-schema rendered once per item.
 *
 * The items are whatever the value holds: a relation's records, or the rows
 * of a JSON column. A row that is not a record is wrapped in an
 * `InfolistRow` so the children are always handed a model — see that class
 * for why the alternative was worse.
 *
 * The children are ordinary entries. Nothing about them changes because they
 * sit in here, which is what keeps a repeatable from becoming a second way to
 * describe a value.
 */
final class RepeatableEntry extends Entry
{
    /** @var list<InfolistComponent> */
    private array $components = [];

    private int $columns = 1;

    private ?string $itemLabel = null;

    public function type(): EntryType
    {
        return EntryType::Repeatable;
    }

    /**
     * @param  array<array-key, InfolistComponent>  $components
     */
    public function schema(array $components): self
    {
        $this->components = array_values($components);

        return $this;
    }

    public function columns(int $columns): self
    {
        $this->columns = ColumnCount::clamp($columns);

        return $this;
    }

    /**
     * A heading each item wears, numbered by position: "Line 1", "Line 2".
     */
    public function itemLabel(string $label): self
    {
        $this->itemLabel = $label;

        return $this;
    }

    /**
     * The repeatable itself, not its children.
     *
     * Its children belong to one item rather than to the record, so counting
     * them among the record's entries would say a value exists at the top
     * level that does not.
     *
     * @return list<Entry>
     */
    public function entries(): array
    {
        return [$this];
    }

    /**
     * @return list<array{label: string|null, schema: list<array<string, mixed>>}>
     */
    public function toValue(Model $record): array
    {
        $rendered = [];

        foreach ($this->items($record) as $index => $item) {
            $schema = [];

            foreach ($this->components as $component) {
                $child = $component->toArray($item);

                if ($child !== null) {
                    $schema[] = $child;
                }
            }

            $rendered[] = [
                'label' => $this->itemLabel === null
                    ? null
                    : sprintf('%s %d', $this->itemLabel, $index + 1),
                'schema' => $schema,
            ];
        }

        return $rendered;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return ['columns' => $this->columns];
    }

    /**
     * @return list<Model>
     */
    private function items(Model $record): array
    {
        $value = $this->resolveValue($record);

        if ($value instanceof EloquentCollection || $value instanceof Collection) {
            $value = $value->all();
        }

        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            // Anything that is neither a record nor a row is not an item.
            // Dropping it here keeps the children from being handed a scalar
            // they would read nothing out of.
            if ($item instanceof Model) {
                $items[] = $item;
            } elseif (is_array($item)) {
                $items[] = InfolistRow::wrap($item);
            }
        }

        return $items;
    }
}
