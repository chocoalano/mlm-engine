<?php

declare(strict_types=1);

namespace PandaPanel\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Exceptions\Halt;

/**
 * The lifecycle hooks a resource page may override.
 *
 * Every one has a working no-op default and is genuinely called, so a page
 * that overrides one gets it invoked without any further wiring.
 *
 * Two kinds, and the split is the point. A `mutate*` hook takes data and
 * returns it; it exists to shape what is shown or saved. Every other hook
 * returns nothing and exists for side effects and for halting. Conflating
 * them would leave two places to change a value and no obvious place to stop.
 *
 * Rendering a form:
 *   beforeFill → mutateFormDataBeforeFill → afterFill
 *
 * Create:
 *   beforeValidate → validate → afterValidate → beforeCreate
 *   → mutateFormDataBeforeCreate → mutateFormDataBeforeSave → beforeSave
 *   → handleRecordCreation → afterCreate → afterSave
 *
 * Update:
 *   beforeValidate → validate → afterValidate
 *   → mutateFormDataBeforeSave → beforeSave → handleRecordUpdate → afterSave
 *
 * Deletion has no hooks here on purpose. It runs through the action
 * endpoint, which executes without a page instance, so a `beforeDelete` on
 * this trait could never be called. Use `Action::before()` and
 * `Action::after()` instead; those share the action's transaction.
 *
 * The persist step and the `after*` hooks share one transaction, so a hook
 * that throws rolls the write back rather than leaving a half-applied
 * change. `halt()` is not a throw in that sense: it stops the lifecycle
 * before anything is written.
 *
 * These are for shaping data and small side effects. Substantial business
 * logic belongs in an action or service class that a hook calls; a hook is
 * not a place to hide a workflow.
 */
trait HasLifecycleHooks
{
    /**
     * Stops the lifecycle. Nothing after the calling hook runs, and nothing
     * is written.
     *
     * @throws Halt
     */
    protected function halt(): never
    {
        throw Halt::make();
    }

    /**
     * Runs before the form is filled, on both create and edit. May halt.
     */
    protected function beforeFill(): void
    {
        //
    }

    /**
     * Shapes the values the form opens with. On create these are the field
     * defaults; on edit they come from the record.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function afterFill(array $data): void
    {
        //
    }

    /**
     * Shapes the raw request body before validation, so a value the user
     * never sees can still be validated.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function beforeValidate(array $input): array
    {
        return $input;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function afterValidate(array $data): array
    {
        return $data;
    }

    /**
     * Runs on create, after validation and before anything is shaped or
     * written. The last place to halt cheaply.
     */
    protected function beforeCreate(): void
    {
        //
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data;
    }

    /**
     * Runs on create and update, after the create-specific mutation.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data, ?Model $record): array
    {
        return $data;
    }

    /**
     * Runs immediately before the write, on create and update.
     */
    protected function beforeSave(?Model $record): void
    {
        //
    }

    protected function afterCreate(Model $record): void
    {
        //
    }

    protected function afterSave(Model $record): void
    {
        //
    }
}
