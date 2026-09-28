<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Illuminate\Database\Eloquent\Model;

/**
 * Anything that can appear in a form schema: a field or a layout container.
 *
 * Layouts nest, fields do not, so flattening for validation and hydration
 * walks this tree once rather than the schema knowing about every layout
 * type.
 */
abstract class FormComponent
{
    /**
     * The fields inside this component, recursively. A field returns itself.
     *
     * @return list<Field>
     */
    abstract public function fields(): array;

    /**
     * The components directly inside this one.
     *
     * `fields()` flattens to leaves, which loses the layouts on the way. A
     * `Relationship` is a layout that owns a write, so something has to be
     * able to find one nested inside a section without also having to know
     * every layout type that could be between them.
     *
     * @return list<FormComponent>
     */
    public function children(): array
    {
        return [];
    }

    /**
     * The serialized component, or null when it is hidden on this page.
     *
     * The page travels with the call so a field hidden on `edit` never
     * reaches the browser at all, rather than being rendered and ignored.
     *
     * @return array<string, mixed>|null
     */
    abstract public function toArray(?Model $record, string $page): ?array;
}
