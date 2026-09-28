<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\RelationManager;

/**
 * Removes the pivot row joining a record to this relation, leaving both
 * records intact.
 *
 * Only offered on a many-to-many, where there is a join row to remove.
 * Detaching a `hasMany` child would mean nulling its foreign key, which is a
 * different decision with a different name — see `DissociateAction`.
 */
final class DetachAction
{
    /**
     * @param  class-string<RelationManager>  $manager
     */
    public static function make(string $manager, Model $owner): Action
    {
        return Action::make('detach')
            ->label(__('panda-panel::actions.relations.detach.label'))
            ->icon('unlink')
            ->variant(ActionVariant::Ghost)
            ->requiresConfirmation(
                heading: __('panda-panel::actions.relations.detach.heading'),
                description: __('panda-panel::actions.relations.detach.description'),
                button: __('panda-panel::actions.relations.detach.button'),
            )
            ->successMessage(__('panda-panel::actions.relations.detach.success'))
            ->visible(static fn (): bool => $manager::isManyToMany($owner))
            ->authorize(static fn (?Model $record): bool => $record !== null
                && $manager::canDetach($owner, $record))
            ->action(static function (Model $record) use ($manager, $owner): void {
                $relation = $manager::relation($owner);

                if ($relation instanceof BelongsToMany) {
                    $relation->detach($record->getKey());
                }
            });
    }
}
