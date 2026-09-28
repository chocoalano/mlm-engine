<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;
use PandaPanel\Support\Label;

/**
 * One kind of entry a builder can hold.
 *
 * A block is a named sub-schema. The name is what a stored entry carries, so
 * it is the thing that must be validated on the way back in: an entry naming
 * a block the builder never declared does not exist, however the request
 * spells it.
 */
final class Block
{
    /** @var list<FormComponent> */
    private array $components = [];

    private ?string $label = null;

    private ?string $icon = null;

    public function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    /**
     * @param  array<array-key, FormComponent>  $components
     */
    public function schema(array $components): self
    {
        $this->components = array_values($components);

        return $this;
    }

    public function label(string $label): self
    {
        $this->label = $label;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label ?? Label::resolve(
            'blocks',
            $this->name,
            fn (): string => Str::headline($this->name),
        );
    }

    /**
     * The components this block is built from, layouts included — see
     * `Repeater::componentsForPaths()`.
     *
     * @return list<FormComponent>
     */
    public function componentsForPaths(): array
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
    public function emptyData(): array
    {
        $data = [];

        foreach ($this->fields() as $field) {
            $data[$field->getName()] = $field->formValue(null);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function dehydrate(array $data, ?Model $record): array
    {
        $result = [];

        foreach ($this->fields() as $field) {
            $name = $field->getName();

            if (! array_key_exists($name, $data) || ! $field->isDehydrated($record)) {
                continue;
            }

            if (! $field->shouldDehydrate($data[$name])) {
                continue;
            }

            $result[$field->getDehydrateKey()] = $field->mutate($data[$name], $record);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $schema = [];

        foreach ($this->components as $component) {
            $child = $component->toArray(null, 'create');

            if ($child !== null) {
                $schema[] = $child;
            }
        }

        return [
            'name' => $this->name,
            'label' => $this->getLabel(),
            'icon' => $this->icon,
            'schema' => $schema,
            'emptyData' => $this->emptyData(),
        ];
    }
}
