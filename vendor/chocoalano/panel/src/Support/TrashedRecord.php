<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Soft-delete questions asked of a model that may not soft delete at all.
 *
 * Shared by the resource actions and the relation ones — both have to answer
 * the same question about the same trait, and two copies would be two places
 * for the answer to drift.
 *
 * `trashed()`, `restore()`, and `forceDelete()` come from the `SoftDeletes`
 * trait, so calling them on a plain model is a fatal error rather than a
 * false. Every soft-delete action goes through here so that a manager which
 * declared `$softDeletes` against a model that does not use the trait renders
 * an action that never appears, instead of one that 500s when clicked.
 *
 * The checks are per method rather than one `class_uses_recursive` on the
 * trait: a model may implement soft deletion its own way, and asking whether
 * it can answer the question is more honest than asking whose code it copied.
 */
final class TrashedRecord
{
    public static function supports(Model $record): bool
    {
        return method_exists($record, 'trashed');
    }

    public static function isTrashed(Model $record): bool
    {
        return method_exists($record, 'trashed') && $record->trashed() === true;
    }

    public static function restore(Model $record): void
    {
        if (self::isTrashed($record) && method_exists($record, 'restore')) {
            $record->restore();
        }
    }

    /**
     * `forceDelete()` exists on every model, but only means "past the soft
     * delete" on one that has a soft delete to get past. On a plain model it
     * is `delete()` under another name, and calling it is correct either way.
     */
    public static function forceDelete(Model $record): void
    {
        $record->forceDelete();
    }
}
