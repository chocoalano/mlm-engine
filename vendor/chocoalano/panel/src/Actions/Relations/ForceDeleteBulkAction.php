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
 * Deletes every selected related record for good.
 */
final class ForceDeleteBulkAction
{
    /**
     * @param  class-string<RelationManager>  $manager
     */
    public static function make(string $manager, Model $owner): Action
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
            ->visible(static fn (): bool => $manager::usesSoftDeletes($owner))
            ->authorize(static fn (?Model $record): bool => $record === null
                || $manager::canForceDelete($owner, $record))
            ->bulkAction(static function (Collection $records) use ($manager, $owner): void {
                foreach ($records as $record) {
                    if (! $manager::canForceDelete($owner, $record)) {
                        throw new HttpException(403, __('panda-panel::actions.force_delete_bulk.denied'));
                    }
                }

                DB::transaction(static function () use ($records): void {
                    $records->each(static fn (Model $record) => TrashedRecord::forceDelete($record));
                });
            });
    }
}
