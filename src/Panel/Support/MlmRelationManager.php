<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Support;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaPanel\Resources\RelationManager;

/**
 * A read-only table of an MLM record's related records.
 *
 * The framework offers create, attach and associate on every relation it
 * can; here each is refused, as are edit, delete, detach and dissociate.
 */
abstract class MlmRelationManager extends RelationManager
{
    abstract protected static function viewPermission(): string;

    /** The key of the title under `mlm::mlm.relations`. */
    abstract protected static function titleKey(): string;

    public static function title(): string
    {
        return __('mlm::mlm.relations.'.static::titleKey());
    }

    public static function canViewAny(Model $owner): bool
    {
        return MlmPermission::allows(static::viewPermission());
    }

    public static function canView(Model $owner, Model $record): bool
    {
        return MlmPermission::allows(static::viewPermission());
    }

    public static function canCreate(Model $owner): bool
    {
        return false;
    }

    public static function canEdit(Model $owner, Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $owner, Model $record): bool
    {
        return false;
    }

    public static function canRestore(Model $owner, Model $record): bool
    {
        return false;
    }

    public static function canForceDelete(Model $owner, Model $record): bool
    {
        return false;
    }

    public static function canAttach(Model $owner): bool
    {
        return false;
    }

    public static function canDetach(Model $owner, Model $record): bool
    {
        return false;
    }

    public static function canAssociate(Model $owner): bool
    {
        return false;
    }

    public static function canDissociate(Model $owner, Model $record): bool
    {
        return false;
    }
}
