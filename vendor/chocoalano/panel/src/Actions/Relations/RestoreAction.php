<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Relations;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Support\TrashedRecord;

/**
 * Restores a soft-deleted related record.
 *
 * Hidden for a record that is not deleted, so the row shows either restore or
 * delete and never both. The trashed filter is what puts a deleted record on
 * screen in the first place; without it this action has nothing to appear on.
 */
final class RestoreAction
{
    /**
     * @param  class-string<RelationManager>  $manager
     */
    public static function make(string $manager, Model $owner): Action
    {
        return Action::make('restore')
            ->label(__('panda-panel::actions.restore.label'))
            ->icon('rotate-ccw')
            ->variant(ActionVariant::Ghost)
            ->successMessage(__('panda-panel::actions.restore.success'))
            ->visible(static fn (?Model $record): bool => $record !== null
                && TrashedRecord::isTrashed($record))
            ->authorize(static fn (?Model $record): bool => $record !== null
                && $manager::canRestore($owner, $record))
            ->action(static function (Model $record): void {
                TrashedRecord::restore($record);
            });
    }
}
