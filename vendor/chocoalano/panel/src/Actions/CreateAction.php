<?php

declare(strict_types=1);

namespace PandaPanel\Actions;

use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * Creates a record of the resource.
 *
 * Two shapes, deliberately: `make()` sends the user to the create page, and
 * `modal()` opens the same form in a dialog without leaving the list. Which
 * one is right depends on the form — one with four fields does not deserve a
 * page — so the resource chooses rather than the framework guessing.
 *
 * Both go through `Resource::form()`, so the two cannot validate or persist
 * differently. Override the write with `->tableAction(...)` when creating is
 * not a plain insert.
 */
final class CreateAction
{
    /**
     * A link to the resource's create page.
     *
     * @param  class-string<PanelResource>  $resource
     */
    public static function make(string $resource): Action
    {
        return Action::make('create')
            ->label(__('panda-panel::actions.create.label', [
                'label' => mb_strtolower($resource::label()),
            ]))
            ->icon('plus')
            ->variant(ActionVariant::Default)
            ->url(static fn (): string => $resource::url('create'))
            ->visible(static fn (): bool => array_key_exists('create', $resource::pages()))
            ->authorize(static fn (): bool => $resource::canCreate());
    }

    /**
     * The same form, opened in a dialog.
     *
     * A table action rather than a record one: it acts on the table, so it
     * carries no record and is authorized with none — exactly as a bulk
     * action is before anything is selected.
     *
     * @param  class-string<PanelResource>  $resource
     */
    public static function modal(string $resource): Action
    {
        return Action::make('create')
            ->label(__('panda-panel::actions.create.label', [
                'label' => mb_strtolower($resource::label()),
            ]))
            ->icon('plus')
            ->variant(ActionVariant::Default)
            ->modalHeading(__('panda-panel::actions.create.modal_heading', [
                'label' => mb_strtolower($resource::label()),
            ]))
            ->modalSubmitLabel(__('panda-panel::actions.create.submit'))
            ->successMessage(__('panda-panel::actions.create.success', [
                'label' => $resource::label(),
            ]))
            ->schema(static fn (): FormSchema => $resource::form(
                FormSchema::make()->model($resource::getModel())->forPage('create'),
            ))
            ->authorize(static fn (): bool => $resource::canCreate())
            ->tableAction(static function (array $data) use ($resource): void {
                $model = $resource::getModel();

                // Through the model rather than through `query()`: a scope
                // describes what the list shows, and a new record has not
                // been written yet for a scope to have an opinion about.
                (new $model)->forceFill($data)->save();
            });
    }
}
