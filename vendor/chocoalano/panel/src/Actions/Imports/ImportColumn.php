<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Imports;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Support\Label;

/**
 * One column of an import: where a cell lands, and what it must be.
 *
 * The rules are Laravel's, applied per row. That is the whole safety story
 * for an import — a file is request input like any other, and the fact that
 * it arrived as a spreadsheet does not make it trustworthy.
 *
 * `relationship()` is the other half. A file says "Acme Ltd", the column says
 * "that is a `company` by `name`", and the row gets a foreign key. Resolving
 * it here rather than in the importer means the lookup is declared once and
 * the same failure — no such company — is reported per row rather than
 * throwing halfway through the file.
 */
final class ImportColumn
{
    private ?string $label = null;

    /** @var list<string> */
    private array $guesses = [];

    /** @var list<mixed> */
    private array $rules = [];

    private bool $required = false;

    /** @var (Closure(string): mixed)|null */
    private ?Closure $castUsing = null;

    private ?string $relationship = null;

    private ?string $relationshipColumn = null;

    private bool $createRelated = false;

    public function __construct(private readonly string $name)
    {
        if (trim($name) === '') {
            throw PanelSchemaException::emptyName('import column');
        }
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Headings this column recognises itself under.
     *
     * A file exported from somewhere else calls the column "E-mail Address",
     * and asking a person to rename it before importing is asking them to do
     * the computer's job. The mapping step still lets them correct a guess.
     *
     * @param  list<string>  $guesses
     */
    public function guess(array $guesses): self
    {
        $this->guesses = $guesses;

        return $this;
    }

    /**
     * @param  list<mixed>  $rules
     */
    public function rules(array $rules): self
    {
        $this->rules = $rules;

        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    /**
     * Turns the cell's text into the value the column holds.
     *
     * A spreadsheet has no types: every cell is a string, and "1"/"yes"/"TRUE"
     * all mean the same thing to a person and nothing to a boolean column.
     *
     * @param  Closure(string): mixed  $callback
     */
    public function castUsing(Closure $callback): self
    {
        $this->castUsing = $callback;

        return $this;
    }

    /**
     * Resolves the cell against a relation and stores the foreign key.
     *
     * @param  string  $relationship  the relation on the model
     * @param  string  $column  the column on the *related* table to match
     */
    public function relationship(string $relationship, string $column = 'name'): self
    {
        $this->relationship = $relationship;
        $this->relationshipColumn = $column;

        return $this;
    }

    /**
     * Creates the related record when the lookup finds nothing.
     *
     * Off by default: silently creating rows in another table because a cell
     * was misspelled is how an import turns one mistake into two.
     */
    public function createRelated(bool $create = true): self
    {
        $this->createRelated = $create;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label ?? Label::resolve(
            'fields',
            $this->name,
            fn (): string => Str::headline($this->name),
        );
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getRelationship(): ?string
    {
        return $this->relationship;
    }

    /**
     * The headings this column answers to, lowercased for comparison.
     *
     * @return list<string>
     */
    public function headings(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $heading): string => mb_strtolower(trim($heading)),
            [$this->name, $this->getLabel(), ...$this->guesses],
        )));
    }

    /**
     * @return list<mixed>
     */
    public function validationRules(): array
    {
        return [$this->required ? 'required' : 'nullable', ...$this->rules];
    }

    /**
     * The cell as the model will hold it, before the relation is resolved.
     */
    public function cast(string $value): mixed
    {
        $value = trim($value);

        if ($this->castUsing !== null) {
            return ($this->castUsing)($value);
        }

        return $value === '' ? null : $value;
    }

    /**
     * The foreign key for a cell that names a related record.
     *
     * Returns null when nothing matched and the column was not told to create
     * one — the row then fails validation on the key it did not get, which is
     * a better answer than a record quietly attached to nothing.
     */
    public function resolveRelated(Model $model, mixed $value): ?int
    {
        $relation = $this->belongsTo($model);

        if ($relation === null || $value === null || $value === '') {
            return null;
        }

        $related = $relation->getRelated();
        $column = $this->relationshipColumn ?? 'name';

        $match = $related->newQuery()->where($column, $value)->first();

        if ($match === null && $this->createRelated) {
            $match = $related->newQuery()->create([$column => $value]);
        }

        $key = $match?->getKey();

        return is_numeric($key) ? (int) $key : null;
    }

    /**
     * The attribute the resolved value is written to.
     *
     * A relation column writes the foreign key rather than the relation, so
     * the importer never has to know that `company` means `company_id`.
     */
    public function attribute(Model $model): string
    {
        return $this->belongsTo($model)?->getForeignKeyName() ?? $this->name;
    }

    /**
     * The relation, when there is one and it is the kind that stores a key on
     * this record.
     *
     * A `BelongsTo` and nothing else: those are the relations whose value is
     * a column of the row being imported. A `hasMany` cannot be set from one
     * cell of one row, and pretending otherwise would write a foreign key
     * onto the wrong table.
     *
     * @return BelongsTo<Model, Model>|null
     */
    private function belongsTo(Model $model): ?BelongsTo
    {
        if ($this->relationship === null || ! method_exists($model, $this->relationship)) {
            return null;
        }

        $relation = $model->{$this->relationship}();

        return $relation instanceof BelongsTo ? $relation : null;
    }
}
