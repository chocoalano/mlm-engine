<?php

declare(strict_types=1);

namespace PandaPanel\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\TrashedRecord;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Deletes every selected record for good.
 *
 * The most destructive thing the panel can be asked to do, so it authorizes
 * `forceDeleteAny` first, then every record individually, and only then
 * writes — in one transaction, so a selection containing one forbidden record
 * destroys nothing.
 */
final class ForceDeleteBulkAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function make(string $resource): Action
    {
        return Action::make('forceDelete')
            ->label(__('panda-panel::actions.force_delete_bulk.label'))
            ->icon('trash-2')
            ->variant(ActionVariant::Destructive)
            ->requiresConfirmation(
                heading: __('panda-panel::actions.force_delete_bulk.heading'),
                description: __('panda-panel::actions.force_delete_bulk.description'),
                button: __('panda-panel::actions.force_delete_bulk.button'),
            )
            ->successMessage(__('panda-panel::actions.force_delete_bulk.success'))
            ->authorize(static fn (?Model $record): bool => $record === null
                ? $resource::canForceDeleteAny()
                : $resource::canForceDelete($record))
            ->bulkAction(static function (Collection $records) use ($resource): void {
                foreach ($records as $record) {
                    if (! $resource::canForceDelete($record)) {
                        throw new HttpException(403, __('panda-panel::actions.force_delete_bulk.denied'));
                    }
                }

                DB::transaction(static function () use ($records): void {
                    $records->each(static fn (Model $record) => TrashedRecord::forceDelete($record));
                });
            });
    }
}
