<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use PandaPanel\Forms\Enums\FieldType;
use PandaPanel\Forms\Support\CallbackParameters;
use PandaPanel\Forms\Support\FormState;

/**
 * A single- or multiple-choice field, backed either by a static option list
 * or by a relation.
 *
 * Relation options are resolved on the server and serialized as plain
 * value/label pairs. The relation name, the related model class, and the
 * query never reach the browser.
 *
 * The two ways a value is validated are deliberately different. A static list
 * is a whitelist, so the submitted value must be one of its keys. A relation
 * is not: the list is one bounded page of a table that may have thousands of
 * rows, so validity is the database's answer (`exists`) and the options are
 * only what the browser was able to show. Validating a relation against the
 * shown page would refuse a perfectly real key for having sorted too late.
 */
final class Select extends Field
{
    /** @var array<array-key, string> */
    private array $options = [];

    private ?string $relation = null;

    private ?string $titleAttribute = null;

    private bool $searchable = false;

    private bool $multiple = false;

    private int $optionLimit = 50;

    /** @var array{table: string, column: string}|null */
    private ?array $existsIn = null;

    /**
     * Builds the whole option list, replacing both the static list and the
     * relation query.
     */
    private ?Closure $optionsUsing = null;

    /**
     * Narrows the relation query the options come from.
     */
    private ?Closure $modifyOptionsQueryUsing = null;

    /**
     * Options resolved from the relation, filled in by the schema before it
     * serializes. Kept apart from `$options` so a relation-backed field never
     * starts validating against whatever page it happened to render.
     *
     * @var list<array{value: string, label: string}>|null
     */
    private ?array $resolvedOptions = null;

    public function type(): FieldType
    {
        return FieldType::Select;
    }

    /**
     * @param  array<array-key, string>  $options
     */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Options come from a relation on the resource's model.
     *
     * `BelongsTo` writes the foreign key on the record itself. `BelongsToMany`
     * and `MorphToMany` write a set of pivot rows, so the field turns into a
     * multiple select and the schema syncs it after the record is saved —
     * a relation that does not exist yet cannot be synced before the row does.
     */
    public function relationship(string $relation, string $titleAttribute): self
    {
        $this->relation = $relation;
        $this->titleAttribute = $titleAttribute;

        return $this;
    }

    /**
     * Validity is a row existing in `$table`, not membership of the shown
     * options.
     */
    public function existsIn(string $table, string $column): self
    {
        $this->existsIn = ['table' => $table, 'column' => $column];

        return $this;
    }

    public function searchable(bool $searchable = true): self
    {
        $this->searchable = $searchable;

        return $this;
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function optionLimit(int $limit): self
    {
        $this->optionLimit = max(1, $limit);

        return $this;
    }

    /**
     * Builds the option list from whatever the form currently holds.
     *
     * The case this exists for is a select whose choices depend on a sibling:
     *
     *     Select::make('employee_id')
     *         ->searchable()
     *         ->optionsUsing(fn (FormState $state, ?string $search) => Employee::query()
     *             ->where('department_id', $state->get('department_id'))
     *             ->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%"))
     *             ->pluck('name', 'id'))
     *
     * Server-side search knew the term and the field but not the rest of the
     * form, so a dependent select could only ever search the whole table and
     * then refuse most of what it found. The state travels with the request —
     * see `PanelFormOptionsController` — and is narrowed to this schema's own
     * fields before a callback ever sees it.
     *
     * Returns either `[value => label]` or a list of `{value, label}`; both
     * are normalised the same way the static list is.
     *
     * @param  Closure  $callback  receives `FormState`, `Get`, and/or the search term
     */
    public function optionsUsing(Closure $callback): self
    {
        $this->optionsUsing = $callback;

        return $this;
    }

    /**
     * Narrows the query a relation-backed select draws its options from.
     *
     * The smaller half of `optionsUsing()`, for the common case where the
     * relation is right and only the scope is wrong:
     *
     *     Select::make('shift_id')
     *         ->relationship('shift', 'name')
     *         ->searchable()
     *         ->modifyOptionsQueryUsing(
     *             fn (Builder $query, FormState $state) => $query
     *                 ->where('department_id', $state->get('department_id')),
     *         );
     *
     * Ordering, the search term, and the limit are still applied afterwards,
     * so a callback cannot accidentally unbound the list.
     *
     * @param  Closure  $callback  receives the query builder and `FormState`/`Get`
     */
    public function modifyOptionsQueryUsing(Closure $callback): self
    {
        $this->modifyOptionsQueryUsing = $callback;

        return $this;
    }

    /**
     * Whether this select's options depend on the rest of the form.
     *
     * The client asks so it knows to throw away what it has cached when a
     * sibling changes: a dependent list that kept answering from the previous
     * parent is worse than one that is briefly empty.
     */
    public function hasDependentOptions(): bool
    {
        return $this->optionsUsing !== null || $this->modifyOptionsQueryUsing !== null;
    }

    public function getRelation(): ?string
    {
        return $this->relation;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    /**
     * Whether the value belongs to a related table rather than to a column on
     * the record, so the schema knows to sync it after the write.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function writesToPivot(string $modelClass): bool
    {
        if ($this->relation === null) {
            return false;
        }

        $relation = $this->eloquentRelation($modelClass);

        // `MorphToMany` extends `BelongsToMany`, so one check answers for both.
        return $relation instanceof BelongsToMany;
    }

    /**
     * Fills in the options a relation-backed field renders with, and turns a
     * many-to-many one into a multiple select.
     *
     * Called by the schema, which is the only place that knows the resource
     * model. Doing it here rather than in `toArray()` keeps the component
     * signature free of a model class it has no other use for.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function hydrateRelationship(string $modelClass, ?FormState $state = null): void
    {
        if ($this->relation === null) {
            return;
        }

        $relation = $this->eloquentRelation($modelClass);

        if ($relation instanceof BelongsToMany) {
            $this->multiple = true;
        }

        $related = $relation->getRelated();

        $this->existsIn ??= [
            'table' => $related->getTable(),
            'column' => $related->getKeyName(),
        ];

        $this->resolvedOptions = $this->resolveOptions($modelClass, null, $state);
    }

    /**
     * Resolves the list for a select whose options come from a callback
     * rather than from a relation.
     *
     * Separate from `hydrateRelationship()` because it needs no model class:
     * an action's form frequently has none, and a dependent select on one is
     * exactly the case that used to have nowhere to get its options from.
     */
    public function hydrateOptions(?FormState $state = null): void
    {
        if ($this->optionsUsing === null) {
            return;
        }

        $this->resolvedOptions = $this->resolveOptions(null, null, $state);
    }

    /**
     * The value/label pairs the browser receives.
     *
     * `$state` is what the form currently holds, and is what lets a dependent
     * select answer for the parent that is actually selected rather than for
     * the whole table. It is optional because most selects do not depend on
     * anything, and because every caller that predates it still works.
     *
     * @param  class-string<Model>|null  $modelClass  the resource model, needed to resolve a relation
     * @return list<array{value: string, label: string}>
     */
    public function resolveOptions(
        ?string $modelClass = null,
        ?string $search = null,
        ?FormState $state = null,
    ): array {
        $state ??= new FormState;

        // A callback owns the whole list, relation or not: it was given the
        // state precisely so it could decide what the choices are.
        if ($this->optionsUsing !== null) {
            return $this->mapOptions($this->normalizeOptions(CallbackParameters::call(
                $this->optionsUsing,
                [$state, $search],
                $state,
                ['search' => $search, 'term' => $search, 'state' => $state],
            )));
        }

        if ($this->relation === null) {
            return $this->mapOptions($this->options);
        }

        if ($modelClass === null) {
            throw new InvalidArgumentException(
                "The field [{$this->getName()}] uses a relationship, so it needs the resource model to resolve options.",
            );
        }

        $related = $this->eloquentRelation($modelClass)->getRelated();
        $title = $this->titleAttribute ?? $related->getKeyName();

        $query = $related->newQuery();

        if ($this->modifyOptionsQueryUsing !== null) {
            CallbackParameters::call(
                $this->modifyOptionsQueryUsing,
                [$query, $state],
                $state,
                ['query' => $query, 'state' => $state, 'search' => $search],
            );
        }

        if ($search !== null && $search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);
            $query->where($title, 'like', '%'.$escaped.'%');
        }

        /** @var array<array-key, string> $options */
        $options = $query
            ->orderBy($title)
            ->limit($this->optionLimit)
            ->pluck($title, $related->getKeyName())
            ->all();

        return $this->mapOptions($options);
    }

    /**
     * Accepts either shape a callback might reasonably return.
     *
     * `[value => label]` is what `pluck()` gives and what `options()` takes;
     * a list of `{value, label}` is what this field serializes. Neither is
     * more correct, so both are understood rather than one being documented
     * and the other silently producing an empty list.
     *
     * @return array<array-key, string>
     */
    private function normalizeOptions(mixed $options): array
    {
        if ($options instanceof Collection) {
            $options = $options->all();
        }

        if (! is_array($options)) {
            return [];
        }

        $mapped = [];

        foreach ($options as $key => $option) {
            if (is_array($option) && array_key_exists('value', $option) && array_key_exists('label', $option)) {
                $mapped[(string) $option['value']] = (string) $option['label'];

                continue;
            }

            if (is_scalar($option) || $option === null) {
                $mapped[$key] = (string) $option;
            }
        }

        return $mapped;
    }

    /**
     * The keys currently selected, for a multiple select backed by a
     * many-to-many.
     *
     * @return list<string>
     */
    public function relatedKeys(Model $record): array
    {
        if ($this->relation === null) {
            return [];
        }

        $relation = $record->{$this->relation}();

        if (! $relation instanceof BelongsToMany) {
            return [];
        }

        return array_values(array_map(strval(...), $relation->allRelatedIds()->all()));
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        if ($this->multiple) {
            return ['array'];
        }

        if ($this->existsIn !== null) {
            return [Rule::exists($this->existsIn['table'], $this->existsIn['column'])];
        }

        // A relation with no resolved table yet: nothing this layer can say
        // about the value, and inventing a rule from the rendered page would
        // refuse keys that are perfectly real.
        if ($this->relation !== null) {
            return [];
        }

        return $this->options === []
            ? []
            : [Rule::in(array_map(strval(...), array_keys($this->options)))];
    }

    /**
     * The rules for each element of a multiple select.
     *
     * Returned separately because Laravel expects them under a `field.*` key
     * rather than inside the field's own rule list.
     *
     * @return list<mixed>
     */
    public function elementRules(): array
    {
        if (! $this->multiple) {
            return [];
        }

        if ($this->existsIn !== null) {
            return [Rule::exists($this->existsIn['table'], $this->existsIn['column'])];
        }

        return $this->options === []
            ? []
            : [Rule::in(array_map(strval(...), array_keys($this->options)))];
    }

    /**
     * @return string|int|list<string>|null
     */
    /**
     * The value the control binds to.
     *
     * A cast attribute arrives as whatever the model casts it to, and for an
     * enum that is a case object — neither a string nor an int. It used to
     * fall through to null, which is not a display bug: the control rendered
     * empty, the browser submitted the empty value back, and an edit that
     * meant to change somebody's name wrote null over their employment type.
     * The value was lost by saving a form nobody had touched.
     *
     * So a backed enum is unwrapped to the value it is backed by, which is
     * also what the option keys are and what the column stores. `strval()` is
     * deliberately not used for the single case: an int-backed enum is an int
     * on the wire, exactly as a plain int column already is, and stringifying
     * it here would change the contract for every integer select.
     *
     * A pure (non-backed) enum has no value to bind to and still reads as
     * null — there is nothing an option list could match it against.
     */
    protected function castForForm(mixed $value): string|int|array|null
    {
        if ($this->multiple) {
            return is_array($value)
                ? array_values(array_map(
                    static fn (mixed $entry): string => (string) self::backingOf($entry),
                    $value,
                ))
                : [];
        }

        $value = self::backingOf($value);

        return is_string($value) || is_int($value) ? $value : null;
    }

    /**
     * What a value is worth to an option list: a backed enum's own backing
     * value, and anything else unchanged.
     *
     * The same unwrapping `BadgeColumn` does, kept to one expression here
     * rather than becoming a shared abstraction — two call sites in two
     * layers is not yet a framework.
     */
    private static function backingOf(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'options' => $this->resolvedOptions ?? $this->mapOptions($this->options),
            'searchable' => $this->searchable,
            'multiple' => $this->multiple,
            'usesRelationship' => $this->relation !== null,
            // The client throws away what it has cached for this field when a
            // sibling changes. A dependent list that kept answering from the
            // previous parent is worse than one that is briefly empty.
            'dependentOptions' => $this->hasDependentOptions(),
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return Relation<Model, Model, mixed>
     */
    private function eloquentRelation(string $modelClass): Relation
    {
        $name = (string) $this->relation;

        if (! method_exists($modelClass, $name)) {
            throw new InvalidArgumentException(
                "[{$name}] is not a method on [{$modelClass}], so the field [{$this->getName()}] cannot resolve it.",
            );
        }

        $relation = (new $modelClass)->{$name}();

        if (! $relation instanceof Relation) {
            throw new InvalidArgumentException(
                "[{$name}] on [{$modelClass}] is not an Eloquent relation.",
            );
        }

        return $relation;
    }

    /**
     * The attribute a `BelongsTo` writes, so the field can persist to the
     * foreign key rather than to a column named after the relation.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function foreignKeyFor(string $modelClass): ?string
    {
        if ($this->relation === null) {
            return null;
        }

        $relation = $this->eloquentRelation($modelClass);

        return $relation instanceof BelongsTo ? $relation->getForeignKeyName() : null;
    }

    /**
     * @param  array<array-key, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private function mapOptions(array $options): array
    {
        $mapped = [];

        foreach ($options as $value => $label) {
            $mapped[] = ['value' => (string) $value, 'label' => $label];
        }

        return $mapped;
    }
}
