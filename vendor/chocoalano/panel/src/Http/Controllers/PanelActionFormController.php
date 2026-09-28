<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PandaPanel\Actions\Action;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Forms\Support\FormContext;
use PandaPanel\Forms\Support\FormSurface;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\FormEndpoints;
use PandaPanel\Support\ParentRecord;
use PandaPanel\Tables\TableSchema;

/**
 * The form an action carries, and the submit that runs it.
 *
 * Two requests, for the reason a table's row actions are buttons rather than
 * twenty embedded forms: the schema is fetched when the dialog opens, so a
 * list ships one button per record instead of one form per record.
 *
 * Everything is resolved from the schema that declared it. The request names
 * a resource, an action, and a scope; the action is looked up in that
 * resource's table or infolist, and one that was never declared there does
 * not exist however the request spells it. Authorization is asked here, twice
 * — once to describe the form and again to run it — because opening a dialog
 * and performing an operation are two separate permissions in time.
 */
final class PanelActionFormController
{
    /**
     * Where an action can be declared. Anything else is not a place a form
     * can come from, which is why this is an allowlist rather than a string.
     */
    private const SCOPES = ['record', 'table', 'bulk', 'infolist'];

    public function __construct(private readonly PanelManager $manager) {}

    /**
     * Describes the dialog: its form, its copy, and how it behaves.
     */
    public function show(Request $request): JsonResponse
    {
        $panel = $this->currentPanel();

        // One resolver for every form surface — see `FormContext`. It does the
        // same four things this method used to do inline (resolve the
        // resource, bind a nested parent, find the record, find and authorize
        // the action) and does them the same way the options, state, and
        // upload endpoints now do.
        $context = FormContext::resolve($this->manager, $request);

        abort_unless($context->surface === FormSurface::Action, 404);

        $action = $context->action;

        abort_if($action === null, 404, __('panda-panel::errors.unknown_action'));
        abort_unless($action->hasForm(), 400, __('panda-panel::errors.action_no_form'));

        $schema = $context->schema();

        abort_if($schema === null, 400, __('panda-panel::errors.action_no_form'));

        $record = $context->stateRecord();
        $modal = $action->getModal();
        $endpoints = FormEndpoints::forContext($context);

        return response()->json([
            'title' => $modal->getHeading() ?? $action->getLabel(),
            'submitLabel' => $modal->getSubmitLabel() ?? $action->getLabel(),
            'form' => $schema->toArray($record),
            // The submit carries the same context this request did, built
            // here so the browser never assembles a panel URL.
            'submitUrl' => route($panel->routeName('actions.submit'), [], absolute: false),
            // All three carry the action's own context, so a select, a file,
            // and a `live()` field on an action's form are each authorized by
            // the action rather than by the resource — an action the user may
            // not run must not be a way to put a file on a disk, nor a way to
            // read an option list the form itself would have refused.
            'optionsUrl' => $endpoints['options'],
            'uploadUrl' => $endpoints['upload'],
            'formStateUrl' => $endpoints['formState'],
            'context' => [
                'resource' => $context->resource::slugIn($panel),
                'action' => $action->getName(),
                'scope' => $context->scope,
            ],
            'modal' => $modal->toArray(),
        ]);
    }

    /**
     * Runs the action with what its form submitted.
     *
     * The data is validated and dehydrated by the action's own schema, so an
     * extra key in the request body is discarded exactly as it is on a
     * resource form — the handler never sees anything the schema did not
     * declare.
     */
    public function submit(Request $request): RedirectResponse
    {
        $panel = $this->currentPanel();

        $validated = $request->validate([
            'resource' => ['required', 'string'],
            'action' => ['required', 'string'],
            'scope' => ['required', 'string', 'in:'.implode(',', self::SCOPES)],
            'record' => ['nullable'],
            'records' => ['nullable', 'array', 'max:500'],
        ]);

        $resource = $this->resolveResource($panel, (string) $validated['resource']);

        $this->bindParentRecord($request, $resource);

        $scope = (string) $validated['scope'];
        $record = $this->resolveRecord($resource, $validated['record'] ?? null);
        $action = $this->resolveAction($resource, $scope, (string) $validated['action']);

        abort_unless($action->isAuthorizedFor($record), 403);
        abort_unless($action->hasForm(), 400, __('panda-panel::errors.action_no_form'));

        $schema = $action->resolveSchema($record);

        abort_if($schema === null, 400, __('panda-panel::errors.action_no_form'));

        $data = $schema->dehydrate(
            $request->validate($schema->validationRules($record)),
            $record,
        );

        $this->run($action, $scope, $record, $resource, $request, $data);

        return back()->with('success', $action->getSuccessMessage());
    }

    /**
     * @param  class-string<PanelResource>  $resource
     * @param  array<string, mixed>  $data
     */
    private function run(
        Action $action,
        string $scope,
        ?Model $record,
        string $resource,
        Request $request,
        array $data,
    ): void {
        if ($scope === 'bulk') {
            $action->executeBulk($this->selection($resource, $request), $data);

            return;
        }

        if ($record === null) {
            abort_unless($action->isTableExecutable(), 400, __('panda-panel::errors.action_not_executable'));

            $action->executeWithoutRecord($data);

            return;
        }

        abort_unless($action->isExecutable(), 400, __('panda-panel::errors.action_not_executable'));

        $action->execute($record, $data);
    }

    /**
     * @param  class-string<PanelResource>  $resource
     * @return Collection<int, Model>
     */
    private function selection(string $resource, Request $request): Collection
    {
        $keys = [];

        foreach ((array) $request->input('records', []) as $key) {
            if (is_string($key) || is_int($key)) {
                $keys[] = $key;
            }
        }

        $keys = array_values(array_unique($keys));

        abort_if($keys === [], 422, __('panda-panel::errors.invalid_record_keys'));

        $records = $resource::findRecords($keys);

        // Keys outside the resource scope disappear in the lookup, so the
        // count check is what turns that into a visible failure rather than
        // a partial run.
        abort_if($records->count() !== count($keys), 404, __('panda-panel::errors.records_not_found'));

        return $records;
    }

    /**
     * @param  class-string<PanelResource>  $resource
     */
    private function resolveAction(string $resource, string $scope, string $name): Action
    {
        $action = match ($scope) {
            'record' => $this->tableSchema($resource)->getRecordAction($name),
            'table' => $this->tableSchema($resource)->getTableAction($name),
            'bulk' => $this->tableSchema($resource)->getBulkAction($name),
            default => $resource::infolist(InfolistSchema::make())->getAction($name),
        };

        abort_if($action === null, 404, __('panda-panel::errors.unknown_action'));

        return $action;
    }

    /**
     * @param  class-string<PanelResource>  $resource
     */
    private function resolveRecord(string $resource, mixed $key): ?Model
    {
        if ($key === null || $key === '') {
            return null;
        }

        abort_unless(is_string($key) || is_int($key), 422, __('panda-panel::errors.invalid_record_key'));

        // Through the record lookup rather than the list query: an action can
        // legitimately address a record the list hides, a restore being the
        // obvious one.
        $record = $resource::findRecord($key);

        abort_if($record === null, 404);

        return $record;
    }

    /**
     * @param  class-string<PanelResource>  $resource
     */
    private function tableSchema(string $resource): TableSchema
    {
        return $resource::table(TableSchema::make());
    }

    /**
     * Binds the parent a nested resource is scoped to, exactly as the action
     * endpoint does — see `PanelActionController::bindParentRecord()`.
     *
     * @param  class-string<PanelResource>  $resource
     */
    private function bindParentRecord(Request $request, string $resource): void
    {
        if (! $resource::isNested()) {
            return;
        }

        $parentResource = $resource::parentResource();
        $key = $request->input('parent');

        abort_if($parentResource === null, 404);
        abort_unless(is_string($key) || is_int($key), 422, __('panda-panel::errors.invalid_parent_key'));

        $parent = ParentRecord::resolve($parentResource, $key);

        abort_if($parent === null, 404);

        ParentRecord::bind($parent);
    }

    /**
     * @return class-string<PanelResource>
     */
    private function resolveResource(Panel $panel, string $slug): string
    {
        $resource = $this->manager->resources($panel)->bySlug($slug);

        abort_if($resource === null, 404, __('panda-panel::errors.unknown_resource'));

        /** @var class-string<PanelResource> $resource */
        return $resource;
    }

    private function currentPanel(): Panel
    {
        return $this->manager->currentPanel()
            ?? throw PanelRegistrationException::noCurrentPanel();
    }
}
