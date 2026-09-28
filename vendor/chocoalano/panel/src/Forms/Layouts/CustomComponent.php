<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Layouts;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;

/**
 * A layout drawn by a component of this application's own.
 *
 * The counterpart of `CustomField` for content and arrangement rather than
 * input. It may still hold components, so a bespoke wrapper can contain
 * ordinary fields and they behave exactly as they would anywhere else.
 */
final class CustomComponent extends FormComponent
{
    /** @var list<FormComponent> */
    private array $components = [];

    /** @var array<string, mixed> */
    private array $config = [];

    public function __construct(private readonly string $component) {}

    public static function make(string $component): self
    {
        return new self($component);
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
     * @param  array<string, mixed>  $config
     */
    public function config(array $config): self
    {
        $this->config = $config;

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
            'component' => 'custom',
            'componentName' => $this->component,
            'config' => $this->config,
            'schema' => $schema,
        ];
    }
}
