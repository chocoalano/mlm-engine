<?php

declare(strict_types=1);

namespace PandaPanel\Actions;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\TrashedRecord;

/**
 * Brings a soft-deleted record back.
 *
 * Hidden for a record that is not trashed, so a row shows either restore or
 * delete and never both. It needs two other things to be reachable at all:
 * the resource must declare `$softDeletes`, or a trashed record cannot be
 * resolved, and the table needs a `TrashedFilter`, or one never appears on
 * screen for the action to sit on.
 */
final class RestoreAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function make(string $resource): Action
    {
        return Action::make('restore')
            ->label(__('panda-panel::actions.restore.label'))
            ->icon('rotate-ccw')
            ->variant(ActionVariant::Outline)
            ->successMessage(__('panda-panel::actions.restore.success'))
            ->visible(static fn (?Model $record): bool => $record !== null
                && TrashedRecord::isTrashed($record))
            ->authorize(static fn (?Model $record): bool => $record !== null
                && $resource::canRestore($record))
            ->action(static function (Model $record): void {
                TrashedRecord::restore($record);
            });
    }
}
