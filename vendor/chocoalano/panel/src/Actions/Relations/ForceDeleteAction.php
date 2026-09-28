<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Relations;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Support\TrashedRecord;

/**
 * Deletes a soft-deleted related record for good.
 *
 * Only offered on a record that is already deleted: force-deleting a live
 * record would skip the recoverable step the model went to the trouble of
 * having.
 */
final class ForceDeleteAction
{
    /**
     * @param  class-string<RelationManager>  $manager
     */
    public static function make(string $manager, Model $owner): Action
    {
        return Action::make('forceDelete')
            ->label(__('panda-panel::actions.force_delete.label'))
            ->icon('trash-2')
            ->variant(ActionVariant::Destructive)
            ->requiresConfirmation(
                heading: __('panda-panel::actions.force_delete.heading'),
                description: __('panda-panel::actions.force_delete.description'),
                button: __('panda-panel::actions.force_delete.button'),
            )
            ->successMessage(__('panda-panel::actions.force_delete.success'))
            ->visible(static fn (?Model $record): bool => $record !== null
                && TrashedRecord::isTrashed($record))
            ->authorize(static fn (?Model $record): bool => $record !== null
                && $manager::canForceDelete($owner, $record))
            ->action(static function (Model $record): void {
                TrashedRecord::forceDelete($record);
            });
    }
}
