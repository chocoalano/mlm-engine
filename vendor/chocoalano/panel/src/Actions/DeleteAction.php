<?php

declare(strict_types=1);

namespace PandaPanel\Actions;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * Deletes one record.
 *
 * Authorization is asked per record, so a policy that refuses one row still
 * lets the action appear on the others. The action endpoint re-checks before
 * running, so hiding the button is never what protects the record.
 */
final class DeleteAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function make(string $resource): Action
    {
        return Action::make('delete')
            ->label(__('panda-panel::actions.delete.label'))
            ->icon('trash-2')
            ->variant(ActionVariant::Destructive)
            ->requiresConfirmation(
                heading: __('panda-panel::actions.delete.heading'),
                description: __('panda-panel::actions.delete.description'),
                button: __('panda-panel::actions.delete.button'),
            )
            ->successMessage(__('panda-panel::actions.delete.success'))
            ->authorize(static fn (?Model $record): bool => $record !== null
                && $resource::canDelete($record))
            ->action(static function (Model $record): void {
                $record->delete();
            });
    }
}
