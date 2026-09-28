<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Forms\Support\FormContext;
use PandaPanel\Forms\Support\FormSurface;
use PandaPanel\Resources\RelationForm;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Resources\RelationTable;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\DatabaseTransaction;
use PandaPanel\Support\FormEndpoints;
use PandaPanel\Support\RelationEndpoints;
use PandaPanel\Support\RelationOperation;

/**
 * Everything a relation manager can be asked to do.
 *
 * The request names a resource, an owner record, a relation, and an operation
 * — nothing else, and nothing executable. Each name is resolved against this
 * panel's registry, then against the resource's own list of managers, so a
 * relation the resource never declared does not exist however the request
 * spells it. Related records are loaded through `RelationManager::query()`,
 * which starts from the owner's relation, so a key belonging to another owner
 * resolves to nothing rather than to somebody else's row.
 *
 * Two authorization questions are asked on every call and neither substitutes
 * for the other: may this user see the owner at all (`Resource::canView`), and
 * may they perform this operation on the relation.
 *
 * The context travels in the query string rather than the body. The body is
 * the form's values, and a field named `resource` must not be able to point
 * the request somewhere else.
 */
final class PanelRelationController
{
    public function __construct(private readonly PanelManager $manager) {}

    /**
     * The schema for one operation, fetched when its dialog opens.
     *
     * JSON rather than Inertia, for the same reason the search endpoint is:
     * re-rendering the page the user is looking at to answer "what does this
     * form look like" would throw away the table state they arrived with.
     */
    public function form(Request $request): JsonResponse
    {
        [$resource, $manager, $owner] = $this->resolveContext($request);

        $operation = RelationOperation::tryFromRequest($request->query('operation'));

        abort_if($operation === null, 404, __('panda-panel::errors.unknown_relation_operation'));

        $related = $this->resolveRelated($request, $manager, $owner, $operation);

        abort_unless($this->authorizeOperation($manager, $owner, $operation, $related), 403);

        $form = RelationForm::for($manager, $owner, $operation, $related);

        return response()->json([
            'title' => $form->title(),
            'submitLabel' => $form->submitLabel(),
            'form' => $form->toArray($related),
            'submitUrl' => RelationEndpoints::form(
                $resource,
                $manager,
                $owner,
                $operation->value,
                $related,
            ),
            'method' => 'post',
            'optionsUrl' => FormEndpoints::forRelation(
                $resource,
                $manager,
                $owner,
                $operation->value,
                $related,
            ),
            // Carries the same context as the options URL, so a file field on
            // a relation form is authorized by the relation's own abilities
            // rather than by the owning resource's.
            'uploadUrl' => FormEndpoints::uploadForRelation(
                $resource,
                $manager,
                $owner,
                $operation->value,
                $related,
            ),
            // And so a `live()` field on a relation form works at all. It used
            // to be silently inert here: the field declared itself live, the
            // renderer honoured it, and there was no URL to ask.
            'formStateUrl' => FormEndpoints::formStateForRelation(
                $resource,
                $manager,
                $owner,
                $operation->value,
                $related,
            ),
        ]);
    }

    /**
     * Runs one operation.
     *
     * Redirects back rather than answering JSON: the relation table lives on a
     * page, and the page has to re-render for the new row to appear. Validation
     * failures come back the same way, so the dialog shows them beside the
     * fields without a second error format.
     */
    public function save(Request $request): RedirectResponse
    {
        [, $manager, $owner] = $this->resolveContext($request);

        $operation = RelationOperation::tryFromRequest($request->query('operation'));

        abort_if($operation === null, 404, __('panda-panel::errors.unknown_relation_operation'));

        $related = $this->resolveRelated($request, $manager, $owner, $operation);

        abort_unless($this->authorizeOperation($manager, $owner, $operation, $related), 403);

        $form = RelationForm::for($manager, $owner, $operation, $related);

        $validated = validator($request->all(), $form->validationRules($related))->validate();

        $this->assertNotAlreadyRelated($manager, $owner, $operation, $form->relatedKey($validated));

        DatabaseTransaction::run(null, static function () use ($form, $validated, $related): void {
            $form->save($validated, $related);
        });

        return back()->with('success', $this->successMessage($operation, $manager));
    }

    /**
     * The form an action on this manager's table carries.
     *
     * This endpoint is why relation actions with forms did not work. A form
     * action is described by whichever endpoint can resolve it, and the only
     * one that existed looked the action up in the *resource's* table — where
     * a relation manager's actions have never been and never will be. Every
     * one of them answered 404, so the dialog never opened; and because the
     * dialog never opened, the submit that would have carried its values was
     * never made either.
     *
     * The payload is deliberately the same shape `PanelActionFormController`
     * answers with. A dialog is a dialog: the same component renders both, and
     * two payload shapes for one component is two things to keep in step.
     */
    public function actionForm(Request $request): JsonResponse
    {
        $context = FormContext::resolve($this->manager, $request);

        abort_unless($context->surface === FormSurface::RelationAction, 404);

        $action = $context->action;

        abort_if($action === null, 404, __('panda-panel::errors.unknown_action'));
        abort_unless($action->hasForm(), 400, __('panda-panel::errors.action_no_form'));

        $schema = $context->schema();

        abort_if($schema === null, 400, __('panda-panel::errors.action_no_form'));

        $modal = $action->getModal();
        $endpoints = FormEndpoints::forContext($context);
        $record = $context->stateRecord();

        return response()->json([
            'title' => $modal->getHeading() ?? $action->getLabel(),
            'submitLabel' => $modal->getSubmitLabel() ?? $action->getLabel(),
            'form' => $schema->toArray($record),
            // The submit is the same URL as this one, by POST. Its context is
            // already in the query string, which is what lets the body be
            // nothing but the user's values.
            'submitUrl' => RelationEndpoints::actionForm(
                $context->resource,
                (string) $context->relation,
                $context->owner ?? abort(404),
                $action->getName(),
                (string) $context->scope,
                $context->related,
            ),
            'method' => 'post',
            'optionsUrl' => $endpoints['options'],
            'uploadUrl' => $endpoints['upload'],
            'formStateUrl' => $endpoints['formState'],
            'context' => [],
            'modal' => $modal->toArray(),
        ]);
    }

    /**
     * Runs an action on this manager's table with what its form submitted.
     *
     * The data is validated and dehydrated by the action's own schema before
     * the handler sees it, exactly as a resource action's is — so an extra key
     * in the body is discarded rather than passed through, and a required
     * field left empty stops the run before anything is written.
     *
     * A separate route from `action()` rather than a branch inside it. The
     * two carry their context differently and cannot be merged without one of
     * them losing: `action()` reads its context from the body, which is safe
     * only while the body is nothing but context. Here the body is whatever
     * the user typed, and a field named `action` must not be able to run a
     * different one.
     */
    public function submitAction(Request $request): RedirectResponse
    {
        $context = FormContext::resolve($this->manager, $request);

        abort_unless($context->surface === FormSurface::RelationAction, 404);

        $action = $context->action;

        abort_if($action === null, 404, __('panda-panel::errors.unknown_action'));
        abort_unless($action->hasForm(), 400, __('panda-panel::errors.action_no_form'));

        $schema = $context->schema();

        abort_if($schema === null, 400, __('panda-panel::errors.action_no_form'));

        $record = $context->stateRecord();

        $data = $schema->dehydrate(
            $request->validate($schema->validationRules($record)),
            $record,
        );

        if ($context->scope === 'bulk') {
            $records = $this->selection(
                (string) $context->relation,
                $context->owner ?? abort(404),
                $request->input('records'),
            );

            abort_unless(
                $action->isBulkExecutable() || $action->isExecutable(),
                400,
                __('panda-panel::errors.action_not_executable'),
            );

            $action->executeBulk($records, $data);

            return back()->with('success', $action->getSuccessMessage());
        }

        // A header action is about the relation, not a row, so it runs through
        // the same handler a resource's table action does — `tableAction()`,
        // taking the data and no record. The owner is not passed as one: it is
        // already in scope where the action was declared, because
        // `RelationManager::table()` receives it, so a closure that needs it
        // closes over it. Handing it in as `$record` would make every handler
        // signature ambiguous about whether the model is the owner or a row.
        if ($context->scope === 'table') {
            abort_unless($action->isTableExecutable(), 400, __('panda-panel::errors.action_not_executable'));

            $action->executeWithoutRecord($data);

            return back()->with('success', $action->getSuccessMessage());
        }

        abort_if($record === null, 404);
        abort_unless($action->isExecutable(), 400, __('panda-panel::errors.action_not_executable'));

        $action->execute($record, $data);

        return back()->with('success', $action->getSuccessMessage());
    }

    /**
     * Runs an action the manager's table declared, on a row or on the relation.
     *
     * The form-less half of the contract. Anything carrying a form goes to
     * `submitAction()` instead, which validates and dehydrates it first.
     *
     * `scope` decides which whitelist the name is looked up in and whether a
     * row is required — a header action has none, and demanding one was what
     * left a form-less header action with no way to run at all.
     */
    public function action(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'resource' => ['required', 'string'],
            'record' => ['required'],
            'relation' => ['required', 'string'],
            'action' => ['required', 'string'],
            'scope' => ['nullable', 'string', 'in:record,table'],
            // Only a row action is about one. Required below rather than here,
            // so the message names the scope that needed it.
            'related' => ['nullable'],
        ]);

        [, $manager, $owner] = $this->resolveContext($request, $validated);

        $scope = $validated['scope'] ?? 'record';

        $action = $scope === 'table'
            ? RelationTable::headerActionFor($manager, $owner, (string) $validated['action'])
            : RelationTable::actionFor($manager, $owner, (string) $validated['action']);

        abort_if($action === null, 404, __('panda-panel::errors.unknown_action'));

        // An action with a form runs through `submitAction()`, which validates
        // and dehydrates that form first. Reaching it here means the values
        // were never collected, and running the handler with an empty array
        // would be a write the user never described — so it is refused rather
        // than performed with nothing.
        abort_if($action->hasForm(), 400, __('panda-panel::errors.action_requires_form'));

        if ($scope === 'table') {
            abort_unless($action->isTableExecutable(), 400, __('panda-panel::errors.action_not_executable'));
            abort_unless($action->isAuthorizedFor(null), 403);

            $action->executeWithoutRecord();

            return back()->with('success', $action->getSuccessMessage());
        }

        abort_unless($action->isExecutable(), 400, __('panda-panel::errors.action_not_executable'));

        $key = $validated['related'] ?? null;

        abort_unless(is_string($key) || is_int($key), 422, __('panda-panel::errors.invalid_record_key'));

        $related = $manager::resolveRecord($owner, $key);

        abort_if($related === null, 404);
        abort_unless($action->isAuthorizedFor($related), 403);

        $action->execute($related);

        return back()->with('success', $action->getSuccessMessage());
    }

    /**
     * Runs a bulk action the manager's table declared.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'resource' => ['required', 'string'],
            'record' => ['required'],
            'relation' => ['required', 'string'],
            'action' => ['required', 'string'],
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*' => ['required'],
        ]);

        [, $manager, $owner] = $this->resolveContext($request, $validated);

        $action = RelationTable::bulkActionFor($manager, $owner, (string) $validated['action']);

        abort_if($action === null, 404, __('panda-panel::errors.unknown_bulk_action'));
        abort_unless($action->isBulkExecutable() || $action->isExecutable(), 400, __('panda-panel::errors.action_not_executable'));
        abort_unless($action->isAuthorizedFor(null), 403);

        // See `action()`: an action carrying a form has values to collect
        // first, and running it here would run it with none of them.
        abort_if($action->hasForm(), 400, __('panda-panel::errors.action_requires_form'));

        $records = $this->selection($manager, $owner, $validated['records']);

        $action->executeBulk($records);

        return back()->with('success', $action->getSuccessMessage());
    }

    /**
     * The resource, the manager, and the owner record this request addresses.
     *
     * @param  array<string, mixed>|null  $body  the validated body, for the endpoints that carry context there
     * @return array{0: class-string<PanelResource>, 1: class-string<RelationManager>, 2: Model}
     */
    private function resolveContext(Request $request, ?array $body = null): array
    {
        $panel = $this->currentPanel();

        $resourceSlug = $body['resource'] ?? $request->query('resource');
        $relationKey = $body['relation'] ?? $request->query('relation');
        $recordKey = $body['record'] ?? $request->query('record');

        abort_unless(is_string($resourceSlug), 422, __('panda-panel::errors.invalid_resource'));
        abort_unless(is_string($relationKey), 422, __('panda-panel::errors.invalid_relation'));
        abort_unless(is_string($recordKey) || is_int($recordKey), 422, __('panda-panel::errors.invalid_record_key'));

        $resource = $this->manager->resources($panel)->bySlug($resourceSlug);

        abort_if($resource === null, 404, __('panda-panel::errors.unknown_resource'));

        /** @var class-string<PanelResource> $resource */
        $manager = $resource::relationManager($relationKey);

        abort_if($manager === null, 404, __('panda-panel::errors.unknown_relation'));

        $owner = $resource::query()->find($recordKey);

        abort_if($owner === null, 404);

        // Reaching a record's relations at all requires being able to see the
        // record. The relation ability decides the operation; this decides
        // whether the owner is visible in the first place.
        abort_unless($resource::canView($owner), 403);
        abort_unless($manager::canViewAny($owner), 403);

        return [$resource, $manager, $owner];
    }

    /**
     * @param  class-string<RelationManager>  $manager
     */
    private function resolveRelated(
        Request $request,
        string $manager,
        Model $owner,
        RelationOperation $operation,
    ): ?Model {
        if (! $operation->needsRelatedRecord()) {
            return null;
        }

        $key = $request->query('related');

        abort_unless(is_string($key), 422, __('panda-panel::errors.invalid_record_key'));

        $related = $manager::resolveRecord($owner, $key);

        abort_if($related === null, 404);

        return $related;
    }

    /**
     * @param  class-string<RelationManager>  $manager
     */
    private function authorizeOperation(
        string $manager,
        Model $owner,
        RelationOperation $operation,
        ?Model $related,
    ): bool {
        return match ($operation) {
            RelationOperation::Create => $manager::canCreate($owner),
            RelationOperation::Edit => $related !== null && $manager::canEdit($owner, $related),
            RelationOperation::Attach => $manager::isManyToMany($owner) && $manager::canAttach($owner),
            RelationOperation::Associate => $manager::isOneToMany($owner) && $manager::canAssociate($owner),
        };
    }

    /**
     * A record already in the relation cannot be joined to it again.
     *
     * Checked here rather than in the form's rules: the rule would have to
     * name every currently related key, which is a list that grows with the
     * relation. One indexed lookup says the same thing.
     *
     * @param  class-string<RelationManager>  $manager
     */
    private function assertNotAlreadyRelated(
        string $manager,
        Model $owner,
        RelationOperation $operation,
        int|string|null $key,
    ): void {
        if ($key === null) {
            return;
        }

        if ($operation !== RelationOperation::Attach && $operation !== RelationOperation::Associate) {
            return;
        }

        abort_if(
            $manager::query($owner)->whereKey($key)->exists(),
            422,
            __('panda-panel::errors.record_already_related'),
        );
    }

    /**
     * @param  class-string<RelationManager>  $manager
     */
    private function successMessage(RelationOperation $operation, string $manager): string
    {
        $title = $manager::title();

        return match ($operation) {
            RelationOperation::Create => $title.' created.',
            RelationOperation::Edit => $title.' updated.',
            RelationOperation::Attach => $title.' attached.',
            RelationOperation::Associate => $title.' associated.',
        };
    }

    /**
     * The related records a bulk action's form was submitted for.
     *
     * Loaded through the manager's own query, which starts from the owner's
     * relation — so a key belonging to another owner is not found rather than
     * found and operated on. The count check is what turns a key that
     * silently disappeared into a visible failure rather than a partial run.
     *
     * @param  class-string<RelationManager>  $manager
     * @return Collection<int, Model>
     */
    private function selection(string $manager, Model $owner, mixed $records): EloquentCollection
    {
        $keys = $this->scalarKeys($records);

        abort_if($keys === [], 422, __('panda-panel::errors.invalid_record_keys'));

        $found = $manager::query($owner)->whereKey($keys)->get();

        abort_if($found->count() !== count($keys), 404, __('panda-panel::errors.records_not_found'));

        return $found;
    }

    /**
     * @return list<int|string>
     */
    private function scalarKeys(mixed $records): array
    {
        if (! is_array($records)) {
            return [];
        }

        $keys = [];

        foreach ($records as $key) {
            if (is_string($key) || is_int($key)) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function currentPanel(): Panel
    {
        return $this->manager->currentPanel()
            ?? throw PanelRegistrationException::noCurrentPanel();
    }
}
