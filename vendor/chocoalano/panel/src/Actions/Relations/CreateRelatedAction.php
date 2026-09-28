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
 * Opens a dialog that creates a record inside the relation.
 *
 * The created record is saved through the relation rather than through the
 * related model, so its foreign key is the relation's business and never a
 * field the form has to declare.
 */
final class CreateRelatedAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<RelationManager>  $manager
     */
    public static function make(string $resource, string $manager, Model $owner): Action
    {
        return Action::make('create')
            ->label(__('panda-panel::actions.relations.create.label', [
                'title' => $manager::title(),
            ]))
            ->icon('plus')
            ->variant(ActionVariant::Default)
            ->form(static fn (): string => RelationEndpoints::form(
                $resource,
                $manager,
                $owner,
                RelationOperation::Create->value,
            ))
            ->authorize(static fn (): bool => $manager::canCreate($owner));
    }
}
