<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Layouts;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;
use PandaPanel\Forms\Support\CallbackParameters;
use PandaPanel\Forms\Support\FormState;
use PandaPanel\Support\ColumnCount;
use PandaPanel\Support\Label;

/**
 * Fields belonging to a single related record — a `BelongsTo`, a `HasOne`, or
 * a `MorphOne` — edited inside the owner's form.
 *
 * Children are namespaced under the relation (`profile.bio`), which is what
 * makes the whole thing work without a second definition of anything:
 * Laravel validates nested keys natively, the errors come back under the same
 * dotted key the field renders with, and a column named `bio` on the owner
 * can coexist with one on the related record.
 *
 * The write happens after the owner is saved and inside the same transaction.
 * That order is forced for `HasOne` and `MorphOne` — a child cannot carry a
 * foreign key to a row that does not exist yet — and keeping `BelongsTo` on
 * the same path means there is one place where a related record is written
 * rather than two that can drift.
 */
final class Relationship extends FormComponent
{
    /** @var list<FormComponent> */
    private array $components = [];

    private ?string $heading = null;

    private ?string $description = null;

    private int $columns = 1;

    /**
     * Whether a missing related record is created on save.
     *
     * On by default: a form that renders empty inputs for a profile the user
     * does not have yet and then silently discards what they typed is worse
     * than one that creates it.
     */
    private bool $createsMissing = true;

    /**
     * Decides whether this group may be written at all.
     *
     * Null means writable, which is what every group declared before this
     * existed means — see `authorize()`.
     */
    private ?Closure $authorizeUsing = null;

    public function __construct(private readonly string $relation) {}

    /**
     * Guards the write this group performs.
     *
     *     Relationship::make('salary')
     *         ->authorize(fn (?Model $record) => Gate::allows('updateSalary', $record))
     *
     * A form's own permission is one question and a relation group's is
     * another. Embedding a salary in an employee form is a layout decision,
     * and it used to be an authorization decision too: whoever could pass
     * `canEdit()` on the employee could write every group the schema
     * declared, because `saveRelations()` wrote all of them unconditionally.
     * The group did not have to be rendered — a crafted body carrying
     * `salary[amount]` was enough.
     *
     * Hiding or disabling the group is not this. Both are presentation, and
     * a request the browser never made ignores presentation entirely. This is
     * evaluated on the server, from the schema, every time the form is
     * validated or written.
     *
     * Receives whatever it asks for — the record, the operation, the form
     * state — through the same injection every other schema callback uses.
     *
     * @param  Closure  $callback  returning false to refuse the write
     */
    public function authorize(Closure $callback): self
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    /**
     * Whether this group may be written for the record and operation in hand.
     *
     * True when nothing was declared, so a schema written before this existed
     * behaves exactly as it did.
     */
    public function isWritable(?Model $record = null, ?FormState $state = null, ?string $page = null): bool
    {
        if ($this->authorizeUsing === null) {
            return true;
        }

        return (bool) CallbackParameters::call(
            $this->authorizeUsing,
            [$record, $page],
            $state ?? new FormState,
            ['record' => $record, 'page' => $page, 'operation' => $page],
        );
    }

    public static function make(string $relation): self
    {
        return new self($relation);
    }

    /**
     * @param  array<array-key, FormComponent>  $components
     */
    public function schema(array $components): self
    {
        $this->components = array_values($components);

        // Applied once, here, rather than on every read: the schema is rebuilt
        // per request, so mutating the children is cheaper and easier to
        // follow than cloning them on each of the four walks below.
        foreach ($this->fields() as $field) {
            $field->prefixNameWith($this->relation);
        }

        return $this;
    }

    public function heading(string $heading): self
    {
        $this->heading = $heading;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function columns(int $columns): self
    {
        $this->columns = ColumnCount::clamp($columns);

        return $this;
    }

    public function createsMissing(bool $createsMissing = true): self
    {
        $this->createsMissing = $createsMissing;

        return $this;
    }

    public function getRelation(): string
    {
        return $this->relation;
    }

    public function shouldCreateMissing(): bool
    {
        return $this->createsMissing;
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
     * Writes the submitted values to the related record.
     *
     * Runs after the owner has been saved, so a `HasOne` or `MorphOne` has a
     * key to point at. A group whose values are all absent — every field
     * declined to dehydrate — leaves the relation alone rather than creating
     * an empty row.
     *
     * @param  array<string, mixed>  $validated  the whole form's validated input, nested
     */
    public function save(Model $owner, array $validated, ?string $page = null): void
    {
        $relation = $owner->{$this->relation}();

        if (! $relation instanceof Relation) {
            return;
        }

        $attributes = $this->attributes($validated, $owner, $page);

        if ($attributes === []) {
            return;
        }

        $related = $this->existing($owner);

        if ($related === null) {
            if (! $this->createsMissing) {
                return;
            }

            $this->create($owner, $relation, $attributes);

            return;
        }

        $related->forceFill($attributes)->save();
    }

    /**
     * The attributes this group persists, keyed by column.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated, Model $owner, ?string $page = null): array
    {
        $related = $this->existing($owner);
        $attributes = [];

        foreach ($this->fields() as $field) {
            $path = $field->getName();

            // Told which operation this is, so `immutableOn()` and a
            // page-dependent `dehydrated()` mean the same thing inside a
            // relation group as they do on the form's own fields.
            $field->onPage($page);

            if (! self::hasPath($validated, $path)) {
                continue;
            }

            // A child that refuses to be written refuses here too. Without
            // this, `dehydrated(false)` and `immutable()` were honoured on the
            // owner's own attributes and silently ignored on a group's.
            if (! $field->isDehydrated($related)) {
                continue;
            }

            $value = data_get($validated, $path);

            if (! $field->shouldDehydrate($value)) {
                continue;
            }

            $attributes[$field->getDehydrateKey()] = $field->mutate($value, $related);
        }

        return $attributes;
    }

    /**
     * `data_get()` cannot tell a missing key from a null value, and the
     * difference decides whether a field is written at all.
     *
     * @param  array<string, mixed>  $data
     */
    private static function hasPath(array $data, string $path): bool
    {
        $segments = explode('.', $path);
        $cursor = $data;

        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }

    private function existing(Model $owner): ?Model
    {
        $value = $owner->getRelationValue($this->relation);

        return $value instanceof Model ? $value : null;
    }

    /**
     * @param  Relation<Model, Model, mixed>  $relation
     * @param  array<string, mixed>  $attributes
     */
    private function create(Model $owner, Relation $relation, array $attributes): void
    {
        // A `BelongsTo` is the other way round: the new record has to exist
        // before the owner can point at it, so it is saved first and the
        // owner's foreign key is written afterwards.
        if ($relation instanceof BelongsTo) {
            $related = $relation->getRelated()->newInstance();
            $related->forceFill($attributes)->save();

            $relation->associate($related);
            $owner->save();

            return;
        }

        $related = $relation->getRelated()->newInstance();
        $related->forceFill($attributes);

        // `MorphOne` needs its type column as well as its key, and `save()`
        // on the relation is what sets both.
        if ($relation instanceof MorphOne || method_exists($relation, 'save')) {
            $relation->save($related);
        }

        $owner->setRelation($this->relation, $related);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Model $record, string $page): array
    {
        $related = $record === null ? null : $this->existing($record);

        return [
            'component' => 'relationship',
            'relation' => $this->relation,
            'heading' => $this->heading ?? Label::resolve(
                'relations',
                $this->relation,
                fn (): string => Str::headline($this->relation),
            ),
            'description' => $this->description,
            'columns' => $this->columns,
            'schema' => $this->serializeChildren($related, $page),
        ];
    }

    /**
     * Children read their values from the related record, not from the owner.
     *
     * @return list<array<string, mixed>>
     */
    private function serializeChildren(?Model $related, string $page): array
    {
        $serialized = [];

        foreach ($this->components as $component) {
            $child = $component->toArray($related, $page);

            if ($child !== null) {
                $serialized[] = $child;
            }
        }

        return $serialized;
    }
}
