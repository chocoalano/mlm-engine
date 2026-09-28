<?php

declare(strict_types=1);

namespace PandaPanel\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Support\Label;
use PandaPanel\Support\PolicyGate;
use PandaPanel\Tables\TableSchema;

/**
 * A table of one owner record's related records, with its own schema,
 * actions, and authorization.
 *
 * The relation is the scope, and `query()` is the only way to reach a related
 * record — exactly the role `Resource::query()` plays for a resource. That is
 * what makes a key from another owner a 404 here rather than a record the
 * manager will happily edit: the query never starts from the related model,
 * it starts from `$owner->{relationship}()`.
 *
 * Authorization asks two different questions and keeps them apart. Reading and
 * writing the related *record* are abilities on that record's own policy;
 * attaching and detaching are abilities on the *owner's* policy, because
 * whether a tag may be pinned to a post is the post's business, not the tag's.
 */
abstract class RelationManager
{
    /** The relation name on the owner model. */
    protected static string $relationship;

    /** The key this manager is addressed by, in URLs and action payloads. */
    protected static ?string $key = null;

    protected static ?string $title = null;

    protected static ?string $icon = null;

    protected static ?string $recordTitleAttribute = null;

    /**
     * Relations eager loaded on every row, so serializing a column can never
     * lazy load per row — the same rule `Resource::$with` exists for.
     *
     * @var list<string>
     */
    protected static array $with = [];

    /**
     * Offers the trashed filter and the restore/force-delete actions.
     *
     * Declared rather than detected, so a related model that uses
     * `SoftDeletes` for something else does not silently grow a filter this
     * manager never meant to offer. `usesSoftDeletes()` still has to agree.
     */
    protected static bool $softDeletes = false;

    /**
     * The owner travels with the schema because a relation table's actions
     * are about the pair, not about the related record alone: whether a row
     * may be detached is a question with two subjects, and an action built
     * without the owner could not ask it.
     */
    abstract public static function table(TableSchema $table, Model $owner): TableSchema;

    /**
     * The form for creating and editing a related record.
     *
     * Empty by default: a manager that only lists, attaches, or detaches has
     * no form, and inheriting one would offer a create button that saves
     * nothing.
     */
    public static function form(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema;
    }

    /**
     * The pivot columns an attach or an edit may write.
     *
     * Only fields declared here are validated and persisted to the pivot
     * table, so an extra key in the request body is discarded exactly as it
     * is on a resource form. Empty means the pivot is a plain join row.
     */
    public static function pivotForm(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema;
    }

    public static function relationship(): string
    {
        return static::$relationship;
    }

    /**
     * The class's key. Kebab-cased so it reads the same in a URL as a
     * resource slug does.
     */
    public static function key(): string
    {
        return static::$key ?? Str::kebab(static::$relationship);
    }

    public static function title(): string
    {
        return static::$title ?? Label::resolve(
            'relations',
            static::$relationship,
            static fn (): string => Str::headline(static::$relationship),
        );
    }

    public static function icon(): ?string
    {
        return static::$icon;
    }

    /**
     * The relation instance on this owner.
     *
     * @return Relation<Model, Model, mixed>
     *
     * @throws PanelRegistrationException
     */
    public static function relation(Model $owner): Relation
    {
        $name = static::$relationship;

        if (! method_exists($owner, $name)) {
            throw PanelRegistrationException::unknownRelation($owner::class, $name, static::class);
        }

        $relation = $owner->{$name}();

        if (! $relation instanceof Relation) {
            throw PanelRegistrationException::unknownRelation($owner::class, $name, static::class);
        }

        return $relation;
    }

    /**
     * Every read of this manager goes through here.
     *
     * A record reachable from another owner is simply not in this builder, so
     * an out-of-scope key resolves to nothing rather than to somebody else's
     * row.
     *
     * @return Builder<covariant Model>
     */
    public static function query(Model $owner): Builder
    {
        /** @var Builder<covariant Model> $query */
        $query = static::relation($owner)->getQuery();

        return static::$with === [] ? $query : $query->with(static::$with);
    }

    /**
     * The relation itself, with this manager's eager loads applied.
     *
     * Tables paginate through this rather than through `query()`: a
     * many-to-many hydrates its pivot in `BelongsToMany::paginate()`, and a
     * builder taken out of the relation would produce rows whose pivot
     * columns all read as null.
     *
     * @return Relation<Model, Model, mixed>
     */
    public static function relationForTable(Model $owner): Relation
    {
        $relation = static::relation($owner);

        if (static::$with !== []) {
            $relation->getQuery()->with(static::$with);
        }

        return $relation;
    }

    /**
     * @return class-string<Model>
     */
    public static function getRelatedModel(Model $owner): string
    {
        return static::relation($owner)->getRelated()::class;
    }

    public static function recordTitle(Model $record): string
    {
        $attribute = static::$recordTitleAttribute ?? 'name';

        $value = $record->getAttribute($attribute);

        return is_scalar($value) ? (string) $value : (string) $record->getKey();
    }

    /**
     * Resolves a related record through `query()`.
     *
     * Null rather than an exception: the caller decides whether a missing
     * record is a 404 or a skipped row of a bulk operation.
     *
     * A manager that soft deletes resolves trashed records too, because
     * restoring one is an operation on a record the default scope hides —
     * a lookup that could not see it could never restore it. Which
     * operations are then allowed is still each action's own authorization
     * question, and `RestoreAction` is hidden for a record that is not
     * trashed.
     */
    public static function resolveRecord(Model $owner, int|string $key): ?Model
    {
        $query = static::query($owner);

        if (static::usesSoftDeletes($owner)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query->find($key);
    }

    /**
     * Whether attach and detach are meaningful here.
     *
     * A many-to-many owns a join row that can be added and removed without
     * touching either record. Everything else does not: "detaching" a
     * `hasMany` child means nulling its foreign key, which is
     * dissociation, and is offered separately.
     */
    public static function isManyToMany(Model $owner): bool
    {
        $relation = static::relation($owner);

        // `MorphToMany` extends `BelongsToMany`, so one check answers for both.
        return $relation instanceof BelongsToMany;
    }

    /**
     * Whether a child can be dissociated — its foreign key nulled — instead
     * of deleted.
     */
    public static function isOneToMany(Model $owner): bool
    {
        return static::relation($owner) instanceof HasOneOrMany;
    }

    public static function usesSoftDeletes(Model $owner): bool
    {
        if (! static::$softDeletes) {
            return false;
        }

        return in_array(
            SoftDeletes::class,
            class_uses_recursive(static::getRelatedModel($owner)),
            true,
        );
    }

    /**
     * @return list<string>
     */
    public static function withRelations(): array
    {
        return static::$with;
    }

    // Reading and writing the related record: abilities on its own policy.

    public static function canViewAny(Model $owner): bool
    {
        return static::authorize('viewAny', static::getRelatedModel($owner));
    }

    public static function canView(Model $owner, Model $record): bool
    {
        return static::authorize('view', $record);
    }

    public static function canCreate(Model $owner): bool
    {
        return static::authorize('create', static::getRelatedModel($owner));
    }

    public static function canEdit(Model $owner, Model $record): bool
    {
        return static::authorize('update', $record);
    }

    public static function canDelete(Model $owner, Model $record): bool
    {
        return static::authorize('delete', $record);
    }

    public static function canRestore(Model $owner, Model $record): bool
    {
        return static::authorize('restore', $record);
    }

    public static function canForceDelete(Model $owner, Model $record): bool
    {
        return static::authorize('forceDelete', $record);
    }

    // Membership: abilities on the owner's policy, with the related record as
    // the second argument where there is one.

    public static function canAttach(Model $owner): bool
    {
        return static::authorize('attachAny', $owner);
    }

    public static function canDetach(Model $owner, Model $record): bool
    {
        return static::authorize('detach', $owner, [$record]);
    }

    public static function canAssociate(Model $owner): bool
    {
        return static::authorize('associateAny', $owner);
    }

    public static function canDissociate(Model $owner, Model $record): bool
    {
        return static::authorize('dissociate', $owner, [$record]);
    }

    /**
     * The records that may be attached, which is every related record not
     * already in the relation.
     *
     * Bounded and searched on the server: the browser receives value/label
     * pairs and never the query.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function attachableOptions(Model $owner, ?string $search = null, int $limit = 50): array
    {
        $relation = static::relation($owner);
        $related = $relation->getRelated();

        $query = $related->newQuery()->whereNotIn(
            $related->getQualifiedKeyName(),
            static::query($owner)->select($related->getQualifiedKeyName()),
        );

        $title = static::$recordTitleAttribute ?? 'name';

        if ($search !== null && $search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);
            $query->where($title, 'like', '%'.$escaped.'%');
        }

        $options = [];

        foreach ($query->orderBy($title)->limit(max(1, $limit))->get() as $record) {
            $options[] = [
                'value' => (string) $record->getKey(),
                'label' => static::recordTitle($record),
            ];
        }

        return $options;
    }

    /**
     * Every ability this manager asks goes through here, so strict
     * authorization covers the relation abilities as well as the record ones.
     *
     * @param  Model|class-string  $subject
     * @param  list<mixed>  $arguments
     */
    protected static function authorize(string $ability, Model|string $subject, array $arguments = []): bool
    {
        return PolicyGate::allows($ability, $subject, $arguments);
    }
}
