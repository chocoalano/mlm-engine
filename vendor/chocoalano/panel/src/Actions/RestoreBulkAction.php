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
 * Restores every selected record.
 *
 * `restoreAny` answers before there is a record to ask about, and each record
 * is then authorized individually before any is written — the same two-step
 * shape `DeleteBulkAction` uses, and for the same reason: a selection
 * containing one forbidden record restores nothing rather than restoring the
 * permitted ones and failing halfway.
 *
 * A record in the selection that is not trashed is left alone rather than
 * refused. The user selected rows and asked for them to be restored; the ones
 * already live are in the state that was asked for.
 */
final class RestoreBulkAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function make(string $resource): Action
    {
        return Action::make('restore')
            ->label(__('panda-panel::actions.restore_bulk.label'))
            ->icon('rotate-ccw')
            ->variant(ActionVariant::Outline)
            ->successMessage(__('panda-panel::actions.restore_bulk.success'))
            ->authorize(static fn (?Model $record): bool => $record === null
                ? $resource::canRestoreAny()
                : $resource::canRestore($record))
            ->bulkAction(static function (Collection $records) use ($resource): void {
                foreach ($records as $record) {
                    if (! $resource::canRestore($record)) {
                        throw new HttpException(403, __('panda-panel::actions.restore_bulk.denied'));
                    }
                }

                // Explicitly transactional whatever the panel says: "all or
                // nothing" is what this action authorized for, not a default
                // it inherits.
                DB::transaction(static function () use ($records): void {
                    $records->each(static fn (Model $record) => TrashedRecord::restore($record));
                });
            });
    }
}
