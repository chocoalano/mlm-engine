<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Relations;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\RelationEndpoints;
use PandaPanel\Support\RelationOperation;

/**
 * Joins an existing record to a many-to-many relation.
 *
 * The dialog offers the records not already in the relation, plus whatever
 * pivot columns the manager declared. Both halves are the server's: the
 * option list is built from `RelationManager::attachableOptions()` and the
 * pivot values are validated against `pivotForm()`, so a key the user was
 * never offered and a pivot column the manager never declared are both
 * refused rather than written.
 */
final class AttachAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<RelationManager>  $manager
     */
    public static function make(string $resource, string $manager, Model $owner): Action
    {
        return Action::make('attach')
            ->label(__('panda-panel::actions.relations.attach.label', [
                'title' => $manager::title(),
            ]))
            ->icon('link')
            ->variant(ActionVariant::Outline)
            ->form(static fn (): string => RelationEndpoints::form(
                $resource,
                $manager,
                $owner,
                RelationOperation::Attach->value,
            ))
            ->visible(static fn (): bool => $manager::isManyToMany($owner))
            ->authorize(static fn (): bool => $manager::canAttach($owner));
    }
}
