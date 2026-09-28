<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\RelationManager;

/**
 * What a relation form and its submit are for.
 *
 * A closed set rather than a free string: the operation decides which schema
 * is built, which ability is asked, and which write runs, so a value the
 * server does not recognise must be a 404 and not a fallback.
 */
enum RelationOperation: string
{
    /** A new related record, saved through the relation. */
    case Create = 'create';

    /** An existing related record, resolved through the relation. */
    case Edit = 'edit';

    /** An existing unrelated record, joined by a pivot row. */
    case Attach = 'attach';

    /** An existing unrelated record, joined by writing its foreign key. */
    case Associate = 'associate';

    public static function tryFromRequest(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * Whether the operation works on a record already in the relation.
     */
    public function needsRelatedRecord(): bool
    {
        return $this === self::Edit;
    }

    /**
     * Whether this user may perform the operation on this owner's relation.
     *
     * Lives on the enum because three endpoints ask it — the relation form,
     * that form's select options, and its file uploads — and three copies of
     * the mapping is three places for one of them to drift into being the
     * permissive one.
     *
     * `Attach` and `Associate` also check the shape of the relation. An
     * attach on a one-to-many is not "denied", it is not a thing; answering
     * no is how a request for it stops before the manager is asked to do
     * something it has no way to do.
     *
     * @param  class-string<RelationManager>  $manager
     */
    public function isAuthorized(string $manager, Model $owner): bool
    {
        return match ($this) {
            self::Create => $manager::canCreate($owner),
            self::Edit => $manager::canViewAny($owner),
            self::Attach => $manager::isManyToMany($owner) && $manager::canAttach($owner),
            self::Associate => $manager::isOneToMany($owner) && $manager::canAssociate($owner),
        };
    }
}
