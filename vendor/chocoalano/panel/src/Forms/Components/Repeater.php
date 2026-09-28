<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Enums\FieldType;
use PandaPanel\Support\ColumnCount;

/**
 * A group of fields the user can repeat.
 *
 * The value is a list of maps. That shape is what makes a repeater different
 * from every other field: its children are not fields of the form, they are
 * fields of each *item*, so they validate at `items.*.title` and are
 * dehydrated once per entry.
 *
 * Its children never reach `FormSchema::fields()` — if they did, the schema
 * would validate `title` as a top-level name and persist it to a column of
 * that name. `fields()` returns the repeater alone, and everything nested is
 * this class's business.
 */
final class Repeater extends Field
{
    /** @var list<FormComponent> */
    private array $components = [];

    private ?int $minItems = null;

    private ?int $maxItems = null;

    private bool $reorderable = true;

    private bool $collapsible = false;

    private bool $addable = true;

    private bool $deletable = true;

    private ?string $addLabel = null;

    private int $columns = 1;

    /** @var (Closure(array<string, mixed>, int): ?string)|null */
    private ?Closure $itemLabelUsing = null;

    public function type(): FieldType
    {
        return FieldType::Repeater;
    }

    /**
     * @param  array<array-key, FormComponent>  $components
     */
    public function schema(array $components): self
    {
        $this->components = array_values($components);

        return $this;
    }

    public function minItems(int $min): self
    {
        $this->minItems = max(0, $min);

        return $this;
    }

    public function maxItems(int $max): self
    {
        $this->maxItems = max(1, $max);

        return $this;
    }

    public function reorderable(bool $reorderable = true): self
    {
        $this->reorderable = $reorderable;

        return $this;
    }

    public function collapsible(bool $collapsible = true): self
    {
        $this->collapsible = $collapsible;

        return $this;
    }

    public function addable(bool $addable = true): self
    {
        $this->addable = $addable;

        return $this;
    }

    public function deletable(bool $deletable = true): self
    {
        $this->deletable = $deletable;

        return $this;
    }

    public function addLabel(string $label): self
    {
        $this->addLabel = $label;

        return $this;
    }

    public function columns(int $columns): self
    {
        $this->columns = ColumnCount::clamp($columns);

        return $this;
    }

    /**
     * The heading each entry shows, from its own values.
     *
     * Resolved on the server so an item can be named after whatever it holds
     * without the frontend knowing the shape.
     *
     * @param  Closure(array<string, mixed>, int): ?string  $callback
     */
    public function itemLabel(Closure $callback): self
    {
        $this->itemLabelUsing = $callback;

        return $this;
    }

    /**
     * The repeater itself, never its children.
     *
     * A child returned here would be validated as a top-level name and
     * written to a column called `title`.
     *
     * @return list<Field>
     */
    public function fields(): array
    {
        return [$this];
    }

    /**
     * The components one entry is built from, layouts included.
     *
     * `itemFields()` flattens to leaves, which is right for rules and wrong
     * for addressing: working out where a field writes means walking the same
     * tree the renderer does, layouts and all.
     *
     * @return list<FormComponent>
     */
    public function componentsForPaths(): array
    {
        return $this->components;
    }

    /**
     * The fields of one entry.
     *
     * @return list<Field>
     */
    public function itemFields(): array
    {
        $fields = [];

        foreach ($this->components as $component) {
            $fields = [...$fields, ...$component->fields()];
        }

        return $fields;
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        $rules = ['array'];

        if ($this->minItems !== null) {
            $rules[] = 'min:'.$this->minItems;
        }

        if ($this->maxItems !== null) {
            $rules[] = 'max:'.$this->maxItems;
        }

        return $rules;
    }

    /**
     * The rules for everything inside one entry.
     *
     * A field of an entry lives at `items.*.title`, a key only the field that
     * owns the entries can produce — which is why `FormSchema` delegates here
     * rather than deriving it.
     *
     * A child that owns entries of its own is asked the same question and its
     * answer is prefixed, so a repeater inside a repeater reaches
     * `outer.*.inner.*.name`. Depth is not a parameter anywhere: each level
     * delegates to the one below it, exactly as the schema delegates to the
     * top level. Before this, generation stopped at the nested field, so a
     * `required()` below it was accepted, rendered, serialized — and never
     * enforced.
     *
     * @return array<string, list<mixed>>
     */
    public function nestedRules(?Model $record = null): array
    {
        $rules = [];
        $prefix = $this->getName().'.*.';

        foreach ($this->itemFields() as $field) {
            $rules[$prefix.$field->getName()] = $field->validationRules($record);

            $elementRules = $field->elementRules();

            if ($elementRules !== []) {
                $rules[$prefix.$field->getName().'.*'] = $elementRules;
            }

            // The child answers with paths relative to itself, so prefixing
            // is the whole of the recursion. A field that owns nothing
            // answers with an empty array and costs one call.
            foreach ($field->nestedRules($record) as $path => $nested) {
                $rules[$prefix.$path] = $nested;
            }
        }

        return $rules;
    }

    /**
     * Each entry is dehydrated by the fields that describe it, so a key the
     * schema never declared is discarded here exactly as it is at the top
     * level.
     */
    public function mutate(mixed $value, ?Model $record): mixed
    {
        if (! is_array($value)) {
            return parent::mutate([], $record);
        }

        $items = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $item = [];

            foreach ($this->itemFields() as $field) {
                $name = $field->getName();

                if (! array_key_exists($name, $entry) || ! $field->isDehydrated($record)) {
                    continue;
                }

                if (! $field->shouldDehydrate($entry[$name])) {
                    continue;
                }

                $item[$field->getDehydrateKey()] = $field->mutate($entry[$name], $record);
            }

            $items[] = $item;
        }

        return parent::mutate($items, $record);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function castForForm(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $entry) {
            if (is_array($entry)) {
                $items[] = $entry;
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'schema' => $this->serializeItemSchema(),
            'minItems' => $this->minItems,
            'maxItems' => $this->maxItems,
            'reorderable' => $this->reorderable,
            'collapsible' => $this->collapsible,
            'addable' => $this->addable,
            'deletable' => $this->deletable,
            'addLabel' => $this->addLabel ?? 'Add item',
            'columns' => $this->columns,
            'itemLabels' => $this->itemLabels(),
            'emptyItem' => $this->emptyItem(),
        ];
    }

    /**
     * The blank entry the frontend adds, built from the fields' own defaults
     * rather than invented in Vue.
     *
     * @return array<string, mixed>
     */
    private function emptyItem(): array
    {
        $item = [];

        foreach ($this->itemFields() as $field) {
            $item[$field->getName()] = $field->formValue(null);
        }

        return $item;
    }

    /**
     * @return list<string|null>
     */
    private function itemLabels(): array
    {
        if ($this->itemLabelUsing === null) {
            return [];
        }

        $labels = [];

        foreach ($this->castForForm($this->currentState) as $index => $entry) {
            $labels[] = ($this->itemLabelUsing)($entry, $index);
        }

        return $labels;
    }

    /**
     * The value being serialized, so item labels can be resolved from it.
     *
     * Held for the duration of one `toArray()` rather than passed through
     * `extraArray()`, whose signature every other field shares.
     */
    private mixed $currentState = null;

    public function toArray(?Model $record, string $page): ?array
    {
        $this->currentState = $this->formValue($record);

        $array = parent::toArray($record, $page);

        $this->currentState = null;

        return $array;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeItemSchema(): array
    {
        $serialized = [];

        foreach ($this->components as $component) {
            // An item's fields are rendered on whatever page the repeater is
            // on, and against no record: each entry is a plain map, not a
            // model, so a field reads its default rather than an attribute.
            $child = $component->toArray(null, 'create');

            if ($child !== null) {
                $serialized[] = $child;
            }
        }

        return $serialized;
    }
}
