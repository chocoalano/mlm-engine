<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Support\TrashedRecord;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Restores every selected related record.
 *
 * The relation counterpart of the resource action: the ability is the related
 * record's own, the scope is the owner's relation, and the whole set runs in
 * one transaction after every record has been authorized.
 */
final class RestoreBulkAction
{
    /**
     * @param  class-string<RelationManager>  $manager
     */
    public static function make(string $manager, Model $owner): Action
    {
        return Action::make('restore')
            ->label(__('panda-panel::actions.restore_bulk.label'))
            ->icon('rotate-ccw')
            ->variant(ActionVariant::Outline)
            ->successMessage(__('panda-panel::actions.restore_bulk.success'))
            ->visible(static fn (): bool => $manager::usesSoftDeletes($owner))
            ->authorize(static fn (?Model $record): bool => $record === null
                || $manager::canRestore($owner, $record))
            ->bulkAction(static function (Collection $records) use ($manager, $owner): void {
                foreach ($records as $record) {
                    if (! $manager::canRestore($owner, $record)) {
                        throw new HttpException(403, __('panda-panel::actions.restore_bulk.denied'));
                    }
                }

                DB::transaction(static function () use ($records): void {
                    $records->each(static fn (Model $record) => TrashedRecord::restore($record));
                });
            });
    }
}
