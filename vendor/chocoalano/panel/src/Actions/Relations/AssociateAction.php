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
 * Adopts an existing record into a one-to-many relation by writing its
 * foreign key.
 *
 * The counterpart of `DissociateAction`, and the one-to-many answer to
 * `AttachAction`: there is no join row to create, so the child simply changes
 * whom it belongs to. Offered only on a `hasMany` or `hasOne`, where that
 * question has an answer.
 */
final class AssociateAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<RelationManager>  $manager
     */
    public static function make(string $resource, string $manager, Model $owner): Action
    {
        return Action::make('associate')
            ->label(__('panda-panel::actions.relations.associate.label', [
                'title' => $manager::title(),
            ]))
            ->icon('link')
            ->variant(ActionVariant::Outline)
            ->form(static fn (): string => RelationEndpoints::form(
                $resource,
                $manager,
                $owner,
                RelationOperation::Associate->value,
            ))
            ->visible(static fn (): bool => $manager::isOneToMany($owner))
            ->authorize(static fn (): bool => $manager::canAssociate($owner));
    }
}
