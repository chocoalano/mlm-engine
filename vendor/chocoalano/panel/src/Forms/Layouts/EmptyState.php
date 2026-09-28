<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Layouts;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;

/**
 * A stand-in for a part of a schema that has nothing to show.
 *
 * Not the same as a table's empty state, which is about rows. This is about a
 * *section*: a relation with no records yet, a step that only applies once
 * something else exists. Rendering nothing there leaves a gap the reader has
 * to interpret; saying why is better.
 */
final class EmptyState extends FormComponent
{
    private ?string $description = null;

    private ?string $icon = null;

    public function __construct(private readonly string $heading) {}

    public static function make(string $heading): self
    {
        return new self($heading);
    }

    public function description(string $description): self
    {
        $this->description = $description;

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

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Model $record, string $page): array
    {
        return [
            'component' => 'empty-state',
            'heading' => $this->heading,
            'description' => $this->description,
            'icon' => $this->icon,
        ];
    }
}
