<?php

declare(strict_types=1);

namespace PandaPanel\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\Resource as PanelResource;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Deletes every selected record.
 *
 * Each record is authorized individually and the whole set runs in one
 * transaction, so a selection containing one forbidden record deletes
 * nothing rather than deleting the permitted ones and failing halfway.
 */
final class DeleteBulkAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function make(string $resource): Action
    {
        return Action::make('delete')
            ->label(__('panda-panel::actions.delete_bulk.label'))
            ->icon('trash-2')
            ->variant(ActionVariant::Destructive)
            ->requiresConfirmation(
                heading: __('panda-panel::actions.delete_bulk.heading'),
                description: __('panda-panel::actions.delete_bulk.description'),
                button: __('panda-panel::actions.delete_bulk.button'),
            )
            ->successMessage(__('panda-panel::actions.delete_bulk.success'))
            ->authorize(static fn (?Model $record): bool => $record === null
                ? $resource::canDeleteAny()
                : $resource::canDelete($record))
            ->bulkAction(static function (Collection $records) use ($resource): void {
                foreach ($records as $record) {
                    if (! $resource::canDelete($record)) {
                        throw new HttpException(403, __('panda-panel::actions.delete_bulk.denied'));
                    }
                }

                // Explicitly transactional whatever the panel says: this
                // action authorizes every record before deleting any, and
                // "all or nothing" is the guarantee it advertises, not a
                // default it inherits.
                DB::transaction(static function () use ($records): void {
                    $records->each(static fn (Model $record) => $record->delete());
                });
            });
    }
}
