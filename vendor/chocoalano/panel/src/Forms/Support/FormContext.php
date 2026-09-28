<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use PandaPanel\Actions\Action;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Resources\RelationForm;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Resources\RelationTable;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\ParentRecord;
use PandaPanel\Support\RelationOperation;
use PandaPanel\Tables\TableSchema;

/**
 * Which form a side request is about, resolved once and authorized once.
 *
 * A form makes requests other than its submit: a searchable select asks what
 * it may offer, a `live()` field asks what the schema should be now, a file
 * field stores its file. Every one of them has to answer the same four
 * questions before it does anything — which schema is this, which record is
 * it about, which ability guards it, and which model do its options come
 * from — and every one of them used to answer them separately.
 *
 * They are answered here instead. Not for tidiness: four copies of an
 * authorization decision is four chances for one of them to be the permissive
 * one, and the branch that decides *which* schema to build is the same branch
 * that decides which ability to ask. Splitting them is how a form gets built
 * for one context and authorized against another.
 *
 * ## The context is the server's statement
 *
 * It travels in the query string, never in the body. The body is the form's
 * values, and a field that happens to be named `resource` must not be able to
 * point the request at a different one. The client appends only what is
 * genuinely its own — a search term, a field name, the values typed so far.
 *
 * ## Surfaces
 *
 * | Query                                   | Surface           | Ability asked |
 * | --------------------------------------- | ----------------- | ------------- |
 * | `page=create`                           | create form       | `create` |
 * | `page=edit` + `record`                  | edit form         | `update` on it |
 * | `action` + `scope`                      | action form       | the action's own |
 * | `relation` + `operation`                | relation form     | the relation's, per operation |
 * | `relation` + `action` + `scope`         | relation action   | the relation's, then the action's |
 *
 * Most specific first. A relation action is not a resource action even though
 * it is opened from a resource page, and matching on `page` first would let
 * either be authorized as though it were a plain resource write.
 */
final class FormContext
{
    /**
     * Where a resource action can be declared. An allowlist rather than a
     * string, because these are the only places a form can come from.
     *
     * @var list<string>
     */
    public const ACTION_SCOPES = ['record', 'table', 'bulk', 'infolist'];

    /**
     * Where a relation action can be declared.
     *
     * `table` is a relation's *header* action — one about the relation rather
     * than about a row, which is why it resolves without a related record.
     * There is no infolist scope: a relation manager has a table and nothing
     * else.
     *
     * @var list<string>
     */
    public const RELATION_ACTION_SCOPES = ['record', 'table', 'bulk'];

    /**
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<RelationManager>|null  $relation
     */
    private function __construct(
        public readonly FormSurface $surface,
        public readonly Panel $panel,
        public readonly string $resource,
        public readonly ?Model $owner,
        public readonly ?string $relation,
        public readonly ?RelationOperation $operation,
        public readonly ?Model $related,
        public readonly ?Action $action,
        public readonly ?string $scope,
    ) {}

    /**
     * Reads the context out of a request and proves the user may open it.
     *
     * Aborts rather than returning a failure: there is no useful thing a
     * caller could do with "the context is a 403", and giving every endpoint
     * the chance to forget to check is the failure mode this class exists to
     * remove.
     */
    public static function resolve(PanelManager $manager, Request $request): self
    {
        $panel = $manager->currentPanel()
            ?? throw PanelRegistrationException::noCurrentPanel();

        $resource = self::resolveResource($manager, $panel, $request->query('resource'));

        self::bindParentRecord($request, $resource);

        $relationKey = $request->query('relation');
        $actionName = $request->query('action');

        if (is_string($relationKey) && is_string($actionName)) {
            return self::relationAction($panel, $request, $resource, $relationKey, $actionName);
        }

        if (is_string($relationKey)) {
            return self::relation($panel, $request, $resource, $relationKey);
        }

        if (is_string($actionName)) {
            return self::action($panel, $request, $resource, $actionName);
        }

        return self::resourceForm($panel, $request, $resource);
    }

    /**
     * The resource's own create or edit form.
     *
     * `page` is an allowlist rather than "edit, or else create". Defaulting
     * would mean an unrecognised value silently became the create form, and
     * the create form is the one that needs no record — which is exactly the
     * check that would be dodged.
     *
     * @param  class-string<PanelResource>  $resource
     */
    private static function resourceForm(Panel $panel, Request $request, string $resource): self
    {
        $page = $request->query('page');

        abort_unless(in_array($page, ['create', 'edit'], true), 422, __('panda-panel::errors.invalid_page'));

        if ($page === 'create') {
            abort_unless($resource::canCreate(), 403);

            return new self(FormSurface::Create, $panel, $resource, null, null, null, null, null, null);
        }

        $record = self::findRecord($resource, $request->query('record'));

        abort_unless($resource::canEdit($record), 403);

        return new self(FormSurface::Edit, $panel, $resource, $record, null, null, null, null, null);
    }

    /**
     * The form a resource, table, bulk, or infolist action declared.
     *
     * @param  class-string<PanelResource>  $resource
     */
    private static function action(Panel $panel, Request $request, string $resource, string $name): self
    {
        $scope = $request->query('scope');

        abort_unless(in_array($scope, self::ACTION_SCOPES, true), 422, __('panda-panel::errors.invalid_scope'));

        $key = $request->query('record');

        // A table and a bulk action are about no single record, so the record
        // is genuinely optional here — unlike an edit form, where its absence
        // is the check being dodged.
        $record = $key === null || $key === ''
            ? null
            : self::findActionRecord($resource, $key);

        $action = self::resolveResourceAction($resource, (string) $scope, $name);

        abort_unless($action->isAuthorizedFor($record), 403);

        return new self(FormSurface::Action, $panel, $resource, $record, null, null, null, $action, (string) $scope);
    }

    /**
     * The form a relation manager opens for one of its own operations.
     *
     * Two questions, both asked and neither substituting for the other: may
     * this user reach the owner's relations at all, and may they perform this
     * operation on the relation.
     *
     * @param  class-string<PanelResource>  $resource
     */
    private static function relation(Panel $panel, Request $request, string $resource, string $relationKey): self
    {
        [$manager, $owner] = self::resolveRelation($resource, $relationKey, $request->query('record'));

        $operation = RelationOperation::tryFromRequest($request->query('operation'));

        abort_if($operation === null, 404, __('panda-panel::errors.unknown_relation_operation'));

        abort_unless($operation->isAuthorized($manager, $owner), 403);

        $related = $operation->needsRelatedRecord()
            ? self::findRelated($manager, $owner, $request->query('related'))
            : null;

        return new self(FormSurface::Relation, $panel, $resource, $owner, $manager, $operation, $related, null, null);
    }

    /**
     * The form a relation manager's row or bulk action declared.
     *
     * This is the surface that had no endpoint at all. A relation action with
     * a form was sent to the resource action resolver, which looks in the
     * resource's own table and cannot see a relation manager's — so it
     * answered 404 for every one of them.
     *
     * @param  class-string<PanelResource>  $resource
     */
    private static function relationAction(
        Panel $panel,
        Request $request,
        string $resource,
        string $relationKey,
        string $name,
    ): self {
        [$manager, $owner] = self::resolveRelation($resource, $relationKey, $request->query('record'));

        $scope = $request->query('scope') ?? 'record';

        abort_unless(
            in_array($scope, self::RELATION_ACTION_SCOPES, true),
            422,
            __('panda-panel::errors.invalid_scope'),
        );

        $action = match ($scope) {
            'bulk' => RelationTable::bulkActionFor($manager, $owner, $name),
            'table' => RelationTable::headerActionFor($manager, $owner, $name),
            default => RelationTable::actionFor($manager, $owner, $name),
        };

        abort_if($action === null, 404, __('panda-panel::errors.unknown_action'));

        // Only a row action is about one related record. A bulk action is
        // about a selection, authorized record by record when it runs; a
        // header action is about the relation itself and has no row at all —
        // so neither resolves one, and neither may be handed one by the
        // request either.
        $related = $scope === 'record'
            ? self::findRelated($manager, $owner, $request->query('related'))
            : null;

        abort_unless($action->isAuthorizedFor($related), 403);

        return new self(
            FormSurface::RelationAction,
            $panel,
            $resource,
            $owner,
            $manager,
            null,
            $related,
            $action,
            $scope,
        );
    }

    /**
     * The schema this context describes.
     *
     * Null only for an action that declared no form — which is not an error
     * here, because an endpoint may legitimately want to know that before it
     * decides what to say about it.
     */
    public function schema(): ?FormSchema
    {
        return match ($this->surface) {
            FormSurface::Create, FormSurface::Edit => $this->resource::form(
                FormSchema::make()
                    ->model($this->resource::getModel())
                    ->forPage($this->surface->page()),
            ),
            FormSurface::Relation => RelationForm::for(
                (string) $this->relation,
                $this->requireOwner(),
                $this->requireOperation(),
                $this->related,
            )->schema(),
            FormSurface::Action, FormSurface::RelationAction => $this->action?->resolveSchema($this->stateRecord()),
        };
    }

    /**
     * The record whose values the form is populated from.
     *
     * The resource record for an edit form, the related record for a relation
     * action, and nothing at all for a create form or a table action — those
     * describe something that does not exist yet.
     */
    public function stateRecord(): ?Model
    {
        return match ($this->surface) {
            FormSurface::Edit => $this->owner,
            FormSurface::Relation, FormSurface::RelationAction => $this->related,
            default => $this->owner,
        };
    }

    /**
     * The model an option list is drawn from.
     *
     * The relation's related model for a relation surface, the resource's own
     * otherwise. A select on a relation form points at the related model's
     * relations, not the owner's.
     *
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        if ($this->surface->isRelation() && $this->relation !== null && $this->owner !== null) {
            return ($this->relation)::getRelatedModel($this->owner);
        }

        return $this->resource::getModel();
    }

    /**
     * The parameters that name this context, for building the URLs the form's
     * side requests use.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = ['resource' => $this->resource::slugIn($this->panel)];

        if ($this->surface->isResourceForm()) {
            $query['page'] = $this->surface->page();

            if ($this->owner !== null) {
                $query['record'] = (string) $this->owner->getKey();
            }

            return $query;
        }

        if ($this->owner !== null) {
            $query['record'] = (string) $this->owner->getKey();
        }

        if ($this->relation !== null) {
            $query['relation'] = ($this->relation)::key();
        }

        if ($this->operation !== null) {
            $query['operation'] = $this->operation->value;
        }

        if ($this->action !== null) {
            $query['action'] = $this->action->getName();
            $query['scope'] = (string) $this->scope;
        }

        if ($this->related !== null) {
            $query['related'] = (string) $this->related->getKey();
        }

        // An action form that is not about a record still has to say which
        // page's schema it is, or the resource branch has nothing to match.
        if ($this->surface === FormSurface::Action) {
            $query['page'] = $this->surface->page();
        }

        return $query;
    }

    /**
     * The relation manager and owner named by a request, both authorized.
     *
     * @param  class-string<PanelResource>  $resource
     * @return array{0: class-string<RelationManager>, 1: Model}
     */
    private static function resolveRelation(string $resource, string $relationKey, mixed $key): array
    {
        $manager = $resource::relationManager($relationKey);

        abort_if($manager === null, 404, __('panda-panel::errors.unknown_relation'));

        abort_unless(is_string($key) || is_int($key), 422, __('panda-panel::errors.invalid_record_key'));

        // Through the resource's own query, so a key outside its scope —
        // another tenant's row, one the resource hides — is a 404 before any
        // policy runs.
        $owner = $resource::query()->find($key);

        abort_if($owner === null, 404);

        abort_unless($resource::canView($owner), 403);
        abort_unless($manager::canViewAny($owner), 403);

        return [$manager, $owner];
    }

    /**
     * @param  class-string<RelationManager>  $manager
     */
    private static function findRelated(string $manager, Model $owner, mixed $key): Model
    {
        abort_unless(is_string($key) || is_int($key), 422, __('panda-panel::errors.invalid_record_key'));

        // Through the manager's own query, which starts from the owner's
        // relation — so a key belonging to another owner resolves to nothing
        // rather than to somebody else's row.
        $related = $manager::resolveRecord($owner, $key);

        abort_if($related === null, 404);

        return $related;
    }

    /**
     * @param  class-string<PanelResource>  $resource
     */
    private static function findRecord(string $resource, mixed $key): Model
    {
        abort_unless(is_string($key) || is_int($key), 422, __('panda-panel::errors.invalid_record_key'));

        $record = $resource::findRecord($key);

        abort_if($record === null, 404);

        return $record;
    }

    /**
     * @param  class-string<PanelResource>  $resource
     */
    private static function findActionRecord(string $resource, mixed $key): Model
    {
        // Through the record lookup rather than the list query: an action can
        // legitimately address a record the list hides, a restore being the
        // obvious one.
        return self::findRecord($resource, $key);
    }

    /**
     * @param  class-string<PanelResource>  $resource
     */
    private static function resolveResourceAction(string $resource, string $scope, string $name): Action
    {
        $action = match ($scope) {
            'record' => $resource::table(TableSchema::make())->getRecordAction($name),
            'table' => $resource::table(TableSchema::make())->getTableAction($name),
            'bulk' => $resource::table(TableSchema::make())->getBulkAction($name),
            default => $resource::infolist(InfolistSchema::make())->getAction($name),
        };

        abort_if($action === null, 404, __('panda-panel::errors.unknown_action'));

        return $action;
    }

    /**
     * Binds the parent a nested resource is scoped to.
     *
     * Without it every query in a nested resource runs unscoped, so this is
     * done before anything is resolved rather than beside it.
     *
     * @param  class-string<PanelResource>  $resource
     */
    private static function bindParentRecord(Request $request, string $resource): void
    {
        if (! $resource::isNested()) {
            return;
        }

        $parentResource = $resource::parentResource();
        $key = $request->query('parent') ?? $request->input('parent');

        abort_if($parentResource === null, 404);
        abort_unless(is_string($key) || is_int($key), 422, __('panda-panel::errors.invalid_parent_key'));

        $parent = ParentRecord::resolve($parentResource, $key);

        abort_if($parent === null, 404);

        ParentRecord::bind($parent);
    }

    /**
     * @return class-string<PanelResource>
     */
    private static function resolveResource(PanelManager $manager, Panel $panel, mixed $slug): string
    {
        abort_unless(is_string($slug), 422, __('panda-panel::errors.invalid_resource'));

        $resource = $manager->resources($panel)->bySlug($slug);

        abort_if($resource === null, 404, __('panda-panel::errors.unknown_resource'));

        /** @var class-string<PanelResource> $resource */
        return $resource;
    }

    private function requireOwner(): Model
    {
        return $this->owner ?? abort(404);
    }

    private function requireOperation(): RelationOperation
    {
        return $this->operation ?? abort(404, __('panda-panel::errors.unknown_relation_operation'));
    }
}
