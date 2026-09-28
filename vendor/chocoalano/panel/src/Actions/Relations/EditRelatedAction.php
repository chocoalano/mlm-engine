<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Relations;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\RelationEndpoints;
use PandaPanel\Support\RelationOperation;

/**
 * Opens a dialog that edits one related record, pivot columns included.
 *
 * The form URL names the record but carries no schema: the dialog fetches it
 * when it opens, so a page of rows costs one button each rather than one
 * filled-in form each.
 */
final class EditRelatedAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<RelationManager>  $manager
     */
    public static function make(string $resource, string $manager, Model $owner): Action
    {
        return Action::make('edit')
            ->label(__('panda-panel::actions.relations.edit.label'))
            ->icon('pencil')
            ->form(static fn (?Model $record): string => RelationEndpoints::form(
                $resource,
                $manager,
                $owner,
                RelationOperation::Edit->value,
                $record,
            ))
            ->authorize(static fn (?Model $record): bool => $record !== null
                && $manager::canEdit($owner, $record));
    }
}
