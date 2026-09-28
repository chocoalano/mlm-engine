<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * The URLs a relation manager's frontend posts to.
 *
 * Built here and sent as data, so no panel URL is ever constructed in Vue.
 *
 * The context — which resource, which owner, which relation, which operation
 * — travels in the query string rather than in the body. A form's values are
 * the body, and a field happening to be named `resource` must not be able to
 * redirect the request to another resource.
 */
final class RelationEndpoints
{
    /**
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<RelationManager>  $manager
     * @return array{form: string, save: string, action: string, bulk: string, actionForm: string}
     */
    public static function forManager(string $resource, string $manager, Model $owner): array
    {
        $panel = self::panel();

        return [
            'form' => self::contextUrl($panel, 'relations.form', $resource, $manager, $owner),
            'save' => self::contextUrl($panel, 'relations.save', $resource, $manager, $owner),
            'action' => route($panel->routeName('relations.action'), absolute: false),
            'bulk' => route($panel->routeName('relations.bulk'), absolute: false),
            // Where an action this manager's table declared fetches its form.
            // Sent as a base URL rather than one per action: a table of
            // twenty rows would otherwise carry twenty near-identical URLs to
            // open at most one dialog. The client appends the action name,
            // the scope, and the related key — never the resource, the owner,
            // or the relation, which are already fixed here.
            'actionForm' => self::contextUrl($panel, 'relations.action-form-schema', $resource, $manager, $owner),
        ];
    }

    /**
     * The form URL for one of a relation table's actions.
     *
     * Carries the same context the manager's endpoints do, plus the action
     * and the record it is about — so the server resolves the action out of
     * *this* relation's table and authorizes it against a record loaded
     * through *this* relation.
     *
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<RelationManager>  $manager
     */
    public static function actionForm(
        string $resource,
        string $manager,
        Model $owner,
        string $action,
        string $scope = 'record',
        Model|int|string|null $related = null,
    ): string {
        return self::contextUrl(
            self::panel(),
            'relations.action-form-schema',
            $resource,
            $manager,
            $owner,
            [
                'action' => $action,
                'scope' => $scope,
                ...($related === null ? [] : ['related' => self::key($related)]),
            ],
        );
    }

    /**
     * A form URL for one operation, and for one related record when the
     * operation works on one.
     *
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<RelationManager>  $manager
     */
    public static function form(
        string $resource,
        string $manager,
        Model $owner,
        string $operation,
        Model|int|string|null $related = null,
    ): string {
        return self::contextUrl(
            self::panel(),
            'relations.form',
            $resource,
            $manager,
            $owner,
            [
                'operation' => $operation,
                ...($related === null ? [] : ['related' => self::key($related)]),
            ],
        );
    }

    /**
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<RelationManager>  $manager
     * @param  array<string, string>  $extra
     */
    private static function contextUrl(
        Panel $panel,
        string $name,
        string $resource,
        string $manager,
        Model $owner,
        array $extra = [],
    ): string {
        return route($panel->routeName($name), [
            'resource' => $resource::slugIn($panel),
            'record' => self::key($owner),
            'relation' => $manager::key(),
            ...$extra,
        ], absolute: false);
    }

    private static function key(Model|int|string $record): string
    {
        return (string) ($record instanceof Model ? $record->getKey() : $record);
    }

    private static function panel(): Panel
    {
        return app(PanelManager::class)->currentPanel()
            ?? throw PanelRegistrationException::noCurrentPanel();
    }
}
