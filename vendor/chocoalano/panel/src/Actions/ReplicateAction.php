<?php

declare(strict_types=1);

namespace PandaPanel\Actions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * Copies a record.
 *
 * Eloquent's own `replicate()` does the work, which means the copy already
 * excludes the key and the timestamps. What it does not know is which of
 * *this* model's columns must not be duplicated — a unique slug, an invoice
 * number, an API token — so `except()` names them and they are left at their
 * database defaults rather than carried over into a row that will collide.
 *
 * Creating is the ability it needs: a copy is a new record, and being allowed
 * to see one is not being allowed to make another.
 */
final class ReplicateAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     * @param  list<string>  $except  columns the copy must not carry over
     * @param  (Closure(Model, Model): void)|null  $using  adjusts the copy before it is saved
     */
    public static function make(
        string $resource,
        array $except = [],
        ?Closure $using = null,
    ): Action {
        return Action::make('replicate')
            ->label(__('panda-panel::actions.replicate.label'))
            ->icon('copy')
            ->variant(ActionVariant::Outline)
            ->requiresConfirmation(
                heading: __('panda-panel::actions.replicate.heading'),
                description: __('panda-panel::actions.replicate.description'),
                button: __('panda-panel::actions.replicate.button'),
            )
            ->successMessage(__('panda-panel::actions.replicate.success'))
            ->authorize(static fn (?Model $record): bool => $record !== null
                && $resource::canCreate()
                && $resource::canView($record))
            ->action(static function (Model $record) use ($except, $using): void {
                $copy = $record->replicate($except);

                if ($using !== null) {
                    $using($copy, $record);
                }

                $copy->save();
            });
    }
}
