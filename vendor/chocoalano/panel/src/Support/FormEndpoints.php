<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Forms\Support\FormContext;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * The side requests a form makes: fetching options, storing a file, and
 * asking what the schema looks like now.
 *
 * All three are built here and sent with the form, so no panel URL is ever
 * constructed in Vue. Each URL carries the form's context — which resource,
 * which page, and for a relation form which owner and operation — and the
 * client appends only what is genuinely its own: a search term, a field name,
 * the values typed so far. That split is deliberate: the context is the
 * server's statement of what this form is, and a keystroke must not be able
 * to change it.
 */
final class FormEndpoints
{
    /**
     * Where a searchable select fetches the options its first page could not
     * show.
     *
     * @param  class-string<PanelResource>  $resource
     */
    public static function forResource(string $resource, string $page, ?Model $record = null): string
    {
        $panel = self::panel();

        return route($panel->routeName('options'), [
            'resource' => $resource::slugIn($panel),
            'page' => $page,
            ...($record === null ? [] : ['record' => (string) $record->getKey()]),
        ], absolute: false);
    }

    /**
     * The endpoint for one relation form.
     *
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<RelationManager>  $manager
     */
    public static function forRelation(
        string $resource,
        string $manager,
        Model $owner,
        string $operation,
        Model|int|string|null $related = null,
    ): string {
        $panel = self::panel();

        return route($panel->routeName('options'), [
            'resource' => $resource::slugIn($panel),
            'record' => (string) $owner->getKey(),
            'relation' => $manager::key(),
            'operation' => $operation,
            ...($related === null ? [] : [
                'related' => (string) ($related instanceof Model ? $related->getKey() : $related),
            ]),
        ], absolute: false);
    }

    /**
     * Where a file field on a resource form stores its file.
     *
     * The request names a field, never a disk or a directory: both come from
     * the field's own declaration, looked up in the schema this URL names.
     *
     * The record travels in the URL for an edit form, and its absence is what
     * makes a create form a create form. That is not decoration — it is the
     * only thing that lets the endpoint ask `canEdit($record)` rather than
     * settling for "may write something, somewhere, in this resource".
     *
     * @param  class-string<PanelResource>  $resource
     */
    public static function upload(string $resource, string $page, ?Model $record = null): string
    {
        $panel = self::panel();

        return route($panel->routeName('uploads'), [
            'resource' => $resource::slugIn($panel),
            'page' => $page,
            ...($record === null ? [] : ['record' => (string) $record->getKey()]),
        ], absolute: false);
    }

    /**
     * Where a file field on a relation form stores its file.
     *
     * Same context the relation's own form and options endpoints carry, for
     * the same reason: the operation decides which ability is asked, and an
     * upload is a write like any other.
     *
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<RelationManager>  $manager
     */
    public static function uploadForRelation(
        string $resource,
        string $manager,
        Model $owner,
        string $operation,
        Model|int|string|null $related = null,
    ): string {
        $panel = self::panel();

        return route($panel->routeName('uploads'), [
            'resource' => $resource::slugIn($panel),
            'record' => (string) $owner->getKey(),
            'relation' => $manager::key(),
            'operation' => $operation,
            ...($related === null ? [] : [
                'related' => (string) ($related instanceof Model ? $related->getKey() : $related),
            ]),
        ], absolute: false);
    }

    /**
     * Where a file field on an action's form stores its file.
     *
     * An action's form is not the resource's form, and the permission to open
     * it is the action's own — a record action guarded by
     * `->authorize(...)` must guard its uploads by the same rule or the
     * guard is decorative.
     *
     * @param  class-string<PanelResource>  $resource
     */
    public static function uploadForAction(
        string $resource,
        string $action,
        string $scope,
        Model|int|string|null $record = null,
    ): string {
        $panel = self::panel();

        return route($panel->routeName('uploads'), [
            'resource' => $resource::slugIn($panel),
            'action' => $action,
            'scope' => $scope,
            ...($record === null ? [] : [
                'record' => (string) ($record instanceof Model ? $record->getKey() : $record),
            ]),
        ], absolute: false);
    }

    /**
     * Where a `live()` field asks the server to rebuild the schema.
     *
     * The record travels in the URL rather than the body for the same reason
     * the resource does — which record is being edited is the server's
     * statement, not something a keystroke may revise.
     *
     * @param  class-string<PanelResource>  $resource
     */
    public static function formState(string $resource, string $page, ?Model $record = null): string
    {
        $panel = self::panel();

        return route($panel->routeName('form-state'), [
            'resource' => $resource::slugIn($panel),
            'page' => $page,
            ...($record === null ? [] : ['record' => (string) $record->getKey()]),
        ], absolute: false);
    }

    /**
     * Where a `live()` field on a relation form asks the server to rebuild it.
     *
     * Same context the relation's own form, options, and upload endpoints
     * carry, for the same reason: the operation decides which ability is
     * asked, and a rebuild reads the schema that ability guards.
     *
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<RelationManager>  $manager
     */
    public static function formStateForRelation(
        string $resource,
        string $manager,
        Model $owner,
        string $operation,
        Model|int|string|null $related = null,
    ): string {
        $panel = self::panel();

        return route($panel->routeName('form-state'), [
            'resource' => $resource::slugIn($panel),
            'record' => (string) $owner->getKey(),
            'relation' => $manager::key(),
            'operation' => $operation,
            ...($related === null ? [] : [
                'related' => (string) ($related instanceof Model ? $related->getKey() : $related),
            ]),
        ], absolute: false);
    }

    /**
     * The three side endpoints for any form, built from the context the
     * server resolved rather than from a hand-assembled list of parameters.
     *
     * Every surface — create, edit, an action's form, a relation form, a
     * relation action's form — carries the same three, and each carries the
     * same context. Building them one at a time per surface is how a relation
     * action's dialog ended up with an upload URL and no options URL: three
     * call sites, and the one nobody remembered.
     *
     * @return array{options: string, upload: string, formState: string}
     */
    public static function forContext(FormContext $context): array
    {
        return [
            'options' => self::optionsFor($context),
            'upload' => self::uploadFor($context),
            'formState' => self::formStateFor($context),
        ];
    }

    /**
     * Where a searchable select on this form fetches its options.
     */
    public static function optionsFor(FormContext $context): string
    {
        return route(self::panel()->routeName('options'), $context->toQuery(), absolute: false);
    }

    /**
     * Where a file field on this form stores its file.
     */
    public static function uploadFor(FormContext $context): string
    {
        return route(self::panel()->routeName('uploads'), $context->toQuery(), absolute: false);
    }

    /**
     * Where a `live()` field on this form asks the server to rebuild it.
     */
    public static function formStateFor(FormContext $context): string
    {
        return route(self::panel()->routeName('form-state'), $context->toQuery(), absolute: false);
    }

    private static function panel(): Panel
    {
        return app(PanelManager::class)->currentPanel()
            ?? throw PanelRegistrationException::noCurrentPanel();
    }
}
