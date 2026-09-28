<?php

declare(strict_types=1);

namespace PandaPanel\Actions;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * Navigates to a resource's edit page.
 */
final class EditAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function make(string $resource): Action
    {
        return Action::make('edit')
            ->label(__('panda-panel::actions.edit.label'))
            ->icon('pencil')
            ->url(static fn (Model $record): string => $resource::url('edit', $record))
            ->visible(static fn (?Model $record): bool => $record !== null
                && array_key_exists('edit', $resource::pages()))
            ->authorize(static fn (?Model $record): bool => $record !== null
                && $resource::canEdit($record));
    }
}
