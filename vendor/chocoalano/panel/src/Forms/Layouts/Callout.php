<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Layouts;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;
use PandaPanel\Forms\Enums\CalloutTone;

/**
 * A note in the middle of a form.
 *
 * Content, not a control: it holds no fields of its own by default and
 * persists nothing. It exists because the alternative — explaining a
 * consequence in a helper text under one field — puts the explanation
 * somewhere it only applies to that field.
 *
 * It can still wrap components, for a warning that belongs *with* the fields
 * it is about rather than above them.
 */
final class Callout extends FormComponent
{
    /** @var list<FormComponent> */
    private array $components = [];

    private CalloutTone $tone = CalloutTone::Info;

    private ?string $heading = null;

    private ?string $icon = null;

    public function __construct(private readonly string $body) {}

    public static function make(string $body): self
    {
        return new self($body);
    }

    public function tone(CalloutTone $tone): self
    {
        $this->tone = $tone;

        return $this;
    }

    public function heading(string $heading): self
    {
        $this->heading = $heading;

        return $this;
    }

    /**
     * An icon registry key. Null takes the tone's own.
     */
    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
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
     * The same notice, without a form around it.
     *
     * A table's callout carries no nested schema: there is no record being
     * edited and nothing to render inside it. Everything else — the body, the
     * heading, the tone and the icon that tone resolves to — is identical, so
     * `FormCallout.vue` draws both and a warning looks the same wherever the
     * panel shows one.
     *
     * @return array<string, mixed>
     */
    public function toTableArray(): array
    {
        return [
            'component' => 'callout',
            'body' => $this->body,
            'heading' => $this->heading,
            'tone' => $this->tone->value,
            'icon' => $this->icon ?? $this->tone->icon(),
            'schema' => [],
        ];
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
            'component' => 'callout',
            'body' => $this->body,
            'heading' => $this->heading,
            'tone' => $this->tone->value,
            'icon' => $this->icon ?? $this->tone->icon(),
            'schema' => $schema,
        ];
    }
}
