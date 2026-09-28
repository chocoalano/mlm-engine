<?php

declare(strict_types=1);

namespace PandaPanel\Actions;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\TrashedRecord;

/**
 * Deletes a soft-deleted record for good.
 *
 * Only offered on a record that is already trashed: force-deleting a live one
 * would skip the recoverable step the model went to the trouble of having,
 * and the ordinary delete action is what that record should be offered.
 */
final class ForceDeleteAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function make(string $resource): Action
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
                && $resource::canForceDelete($record))
            ->action(static function (Model $record): void {
                TrashedRecord::forceDelete($record);
            });
    }
}
