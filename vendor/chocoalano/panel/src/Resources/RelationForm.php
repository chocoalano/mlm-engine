<?php

declare(strict_types=1);

namespace PandaPanel\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use PandaPanel\Forms\Components\FormComponent;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Support\RelationOperation;

/**
 * The form behind one relation operation, and the write that follows it.
 *
 * A relation form is two schemas standing side by side: the related record's
 * own fields and the pivot's. They are merged for rendering and for
 * validation — the user sees and submits one form — but never for
 * persistence, because they are two rows in two tables. The pivot half is
 * namespaced under `pivot.`, which is what keeps a `role` column on the join
 * table from overwriting a `role` column on the record.
 *
 * Nothing here trusts the submitted key: the field name decides where a value
 * goes, and a key with no field is discarded exactly as it is on a resource
 * form.
 */
final readonly class RelationForm
{
    /** The name of the select that names the record being attached. */
    public const RELATED_FIELD = 'related';

    /** The namespace pivot fields render, validate, and submit under. */
    public const PIVOT_PREFIX = 'pivot';

    /**
     * @param  class-string<RelationManager>  $manager
     */
    private function __construct(
        private string $manager,
        private Model $owner,
        private RelationOperation $operation,
        private FormSchema $schema,
        private bool $hasPivot,
    ) {}

    /**
     * @param  class-string<RelationManager>  $manager
     */
    public static function for(
        string $manager,
        Model $owner,
        RelationOperation $operation,
        ?Model $related = null,
    ): self {
        $relatedClass = $manager::getRelatedModel($owner);
        $page = $operation === RelationOperation::Edit ? 'edit' : 'create';

        $components = match ($operation) {
            RelationOperation::Create, RelationOperation::Edit => $manager::form(
                FormSchema::make()->model($relatedClass)->forPage($page),
                $owner,
            )->getComponents(),
            RelationOperation::Attach, RelationOperation::Associate => [
                self::relatedSelect($manager, $owner, $operation),
            ],
        };

        $pivot = self::pivotComponents($manager, $owner, $operation, $page);

        $schema = FormSchema::make()
            ->model($relatedClass)
            ->forPage($page)
            ->schema([...$components, ...$pivot]);

        return new self($manager, $owner, $operation, $schema, $pivot !== []);
    }

    public function schema(): FormSchema
    {
        return $this->schema;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function validationRules(?Model $related = null): array
    {
        return $this->schema->validationRules($related);
    }

    /**
     * The serialized form, filled from the related record and its pivot.
     *
     * @return array<string, mixed>
     */
    public function toArray(?Model $related = null): array
    {
        return $this->schema->toArray($related);
    }

    public function title(): string
    {
        $manager = $this->manager;

        return match ($this->operation) {
            RelationOperation::Create => 'New '.$manager::title(),
            RelationOperation::Edit => 'Edit '.$manager::title(),
            RelationOperation::Attach => 'Attach '.$manager::title(),
            RelationOperation::Associate => 'Associate '.$manager::title(),
        };
    }

    public function submitLabel(): string
    {
        return match ($this->operation) {
            RelationOperation::Create => 'Create',
            RelationOperation::Edit => 'Save',
            RelationOperation::Attach => 'Attach',
            RelationOperation::Associate => 'Associate',
        };
    }

    /**
     * Runs the operation. The caller has already authorized it and opened the
     * transaction.
     *
     * @param  array<string, mixed>  $validated
     */
    public function save(array $validated, ?Model $related = null): void
    {
        match ($this->operation) {
            RelationOperation::Create => $this->create($validated),
            RelationOperation::Edit => $this->update($validated, $related),
            RelationOperation::Attach => $this->attach($validated),
            RelationOperation::Associate => $this->associate($validated),
        };
    }

    /**
     * The key of the record an attach or associate names, or null.
     *
     * @param  array<string, mixed>  $validated
     */
    public function relatedKey(array $validated): int|string|null
    {
        $key = $validated[self::RELATED_FIELD] ?? null;

        return is_string($key) || is_int($key) ? $key : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function create(array $validated): void
    {
        $manager = $this->manager;
        $relation = $manager::relation($this->owner);
        $attributes = $this->recordAttributes($validated);

        $related = $relation->getRelated()->newInstance();
        $related->forceFill($attributes);

        // Saving through the relation is what sets the foreign key — and the
        // morph type when there is one — so no form has to declare it.
        if ($relation instanceof HasOneOrMany) {
            $relation->save($related);

            return;
        }

        if ($relation instanceof BelongsToMany) {
            $related->save();
            $relation->attach($related->getKey(), $this->pivotAttributes($validated));

            return;
        }

        $related->save();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function update(array $validated, ?Model $related): void
    {
        if ($related === null) {
            return;
        }

        $related->forceFill($this->recordAttributes($validated))->save();

        $pivot = $this->pivotAttributes($validated);
        $relation = $this->manager::relation($this->owner);

        if ($pivot !== [] && $relation instanceof BelongsToMany) {
            $relation->updateExistingPivot($related->getKey(), $pivot);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function attach(array $validated): void
    {
        $key = $this->relatedKey($validated);
        $relation = $this->manager::relation($this->owner);

        if ($key !== null && $relation instanceof BelongsToMany) {
            $relation->attach($key, $this->pivotAttributes($validated));
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function associate(array $validated): void
    {
        $key = $this->relatedKey($validated);
        $relation = $this->manager::relation($this->owner);

        if ($key === null || ! $relation instanceof HasOneOrMany) {
            return;
        }

        $related = $relation->getRelated()->newQuery()->find($key);

        if ($related !== null) {
            $relation->save($related);
        }
    }

    /**
     * The attributes that belong to the related record itself.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function recordAttributes(array $validated): array
    {
        // The select naming the record is addressing, not data: it says which
        // row to join, never what to write into it.
        unset($validated[self::RELATED_FIELD], $validated[self::PIVOT_PREFIX]);

        return $this->schema->dehydrate($validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function pivotAttributes(array $validated): array
    {
        if (! $this->hasPivot) {
            return [];
        }

        $submitted = $validated[self::PIVOT_PREFIX] ?? null;

        if (! is_array($submitted)) {
            return [];
        }

        $manager = $this->manager;
        $attributes = [];

        foreach ($manager::pivotForm(FormSchema::make(), $this->owner)->fields() as $field) {
            $name = $field->getAttribute();

            if (! array_key_exists($name, $submitted)) {
                continue;
            }

            if (! $field->shouldDehydrate($submitted[$name])) {
                continue;
            }

            $attributes[$field->getDehydrateKey()] = $field->mutate($submitted[$name], null);
        }

        return $attributes;
    }

    /**
     * The select naming the record to join.
     *
     * Its options are the records not already in the relation, and its
     * validity is `exists` on the related table rather than membership of
     * that list — the list is one bounded page, and a real key that sorted
     * past the limit is still a real key. The controller checks separately
     * that the record is not already in the relation.
     *
     * @param  class-string<RelationManager>  $manager
     */
    private static function relatedSelect(string $manager, Model $owner, RelationOperation $operation): Select
    {
        $related = $manager::relation($owner)->getRelated();

        $options = [];

        foreach ($manager::attachableOptions($owner) as $option) {
            $options[$option['value']] = $option['label'];
        }

        return Select::make(self::RELATED_FIELD)
            ->label($manager::title())
            ->required()
            ->searchable()
            ->options($options)
            ->existsIn($related->getTable(), $related->getKeyName());
    }

    /**
     * The pivot fields, namespaced so they cannot collide with the related
     * record's own columns.
     *
     * @param  class-string<RelationManager>  $manager
     * @return list<FormComponent>
     */
    private static function pivotComponents(
        string $manager,
        Model $owner,
        RelationOperation $operation,
        string $page,
    ): array {
        // Only a many-to-many has a pivot row to write. Declaring pivot
        // fields on any other relation is a mistake, and rendering them would
        // hide it behind inputs that save nothing.
        if (! $manager::isManyToMany($owner)) {
            return [];
        }

        if ($operation === RelationOperation::Associate) {
            return [];
        }

        $pivot = $manager::pivotForm(FormSchema::make()->forPage($page), $owner);

        foreach ($pivot->fields() as $field) {
            $field->prefixNameWith(self::PIVOT_PREFIX);
        }

        return $pivot->getComponents();
    }
}
