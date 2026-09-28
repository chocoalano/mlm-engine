<?php

declare(strict_types=1);

namespace PandaPanel\Actions;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * Navigates to a resource's view page.
 *
 * A link action, so there is nothing to execute and nothing to post. It
 * still disappears for a user the policy would refuse, and the view route
 * authorizes again on arrival.
 */
final class ViewAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function make(string $resource): Action
    {
        return Action::make('view')
            ->label(__('panda-panel::actions.view.label'))
            ->icon('eye')
            ->url(static fn (Model $record): string => $resource::url('view', $record))
            ->visible(static fn (?Model $record): bool => $record !== null
                && array_key_exists('view', $resource::pages()))
            ->authorize(static fn (?Model $record): bool => $record !== null
                && $resource::canView($record));
    }
}
