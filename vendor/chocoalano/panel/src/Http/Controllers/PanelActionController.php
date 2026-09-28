<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\DatabaseTransaction;
use PandaPanel\Support\ParentRecord;
use PandaPanel\Tables\Columns\EditableColumn;
use PandaPanel\Tables\TableSchema;

/**
 * Executes a resource action.
 *
 * The request names an action, a resource, and record keys. Nothing else. The
 * handler is looked up in the table schema of the resource that was named,
 * inside the panel that was resolved for this request, so an action that the
 * resource never declared simply does not exist. Records are loaded through
 * `Resource::query()`, which means a key outside the resource scope resolves
 * to nothing rather than to a record from elsewhere.
 *
 * Authorization is asked here, not inferred from the button having been
 * rendered.
 */
final class PanelActionController
{
    public function __construct(private readonly PanelManager $manager) {}

    public function record(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'resource' => ['required', 'string'],
            'action' => ['required', 'string'],
            'record' => ['required'],
        ]);

        $panel = $this->currentPanel();
        $resource = $this->resolveResource($panel, (string) $validated['resource']);

        $this->bindParentRecord($request, $resource);

        $schema = $this->schemaFor($resource);

        $action = $schema->getRecordAction((string) $validated['action']);

        abort_if($action === null, 404, __('panda-panel::errors.unknown_action'));
        abort_unless($action->isExecutable(), 400, __('panda-panel::errors.action_not_executable'));

        $key = $validated['record'];

        // A key must be a scalar. Passing an array through would turn
        // `find()` into a collection lookup and quietly change the meaning
        // of the request.
        abort_unless(is_string($key) || is_int($key), 422, __('panda-panel::errors.invalid_record_key'));

        // Through the resource's record lookup, not its list query: a
        // restore is an operation on a record the list deliberately hides, so
        // a lookup that could not see one could never restore it.
        $model = $resource::findRecord($key);

        abort_if($model === null, 404);
        abort_unless($action->isAuthorizedFor($model), 403);

        $action->execute($model);

        return back()->with('success', $action->getSuccessMessage());
    }

    /**
     * Runs an action the record's infolist declared.
     *
     * Separate from `record()` because the whitelist is a different one: a
     * view page's actions are declared by `Resource::infolist()`, and looking
     * them up in the table schema would mean an action shown on one page
     * could be run from the other.
     */
    public function infolist(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'resource' => ['required', 'string'],
            'action' => ['required', 'string'],
            'record' => ['required'],
        ]);

        $panel = $this->currentPanel();
        $resource = $this->resolveResource($panel, (string) $validated['resource']);

        $this->bindParentRecord($request, $resource);

        $action = $resource::infolist(InfolistSchema::make())
            ->getAction((string) $validated['action']);

        abort_if($action === null, 404, __('panda-panel::errors.unknown_action'));
        abort_unless($action->isExecutable(), 400, __('panda-panel::errors.action_not_executable'));

        $key = $validated['record'];

        abort_unless(is_string($key) || is_int($key), 422, __('panda-panel::errors.invalid_record_key'));

        $model = $resource::findRecord($key);

        abort_if($model === null, 404);
        abort_unless($action->isAuthorizedFor($model), 403);

        $action->execute($model);

        return back()->with('success', $action->getSuccessMessage());
    }

    /**
     * Writes an arranged order back to the column the table declared.
     *
     * Every record is authorized for editing before any is touched, and the
     * whole arrangement runs in one transaction: a list that half-reordered
     * would be worse than one that did not move.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'resource' => ['required', 'string'],
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*' => ['required'],
        ]);

        $panel = $this->currentPanel();
        $resource = $this->resolveResource($panel, (string) $validated['resource']);

        $this->bindParentRecord($request, $resource);

        $column = $this->schemaFor($resource)->getReorderColumn();

        abort_if($column === null, 400, __('panda-panel::errors.not_reorderable'));

        $keys = $this->scalarKeys($validated['records']);

        // Arranging an order is about what the list shows, so this one stays
        // on `query()`: a trashed record has no place in the arrangement.
        $records = $resource::query()->findMany($keys);

        abort_unless($records->count() === count($keys), 404);

        foreach ($records as $record) {
            abort_unless($resource::canEdit($record), 403);
        }

        // Position in the submitted list is the order. Sending keys rather
        // than positions keeps the client from inventing values for a column
        // it knows nothing about.
        $positions = array_flip(array_map(strval(...), $keys));

        DB::transaction(static function () use ($records, $column, $positions): void {
            foreach ($records as $record) {
                $record->forceFill([$column => $positions[(string) $record->getKey()]])->save();
            }
        });

        return back()->with('success', __('panda-panel::tables.reordered'));
    }

    /**
     * Writes one cell of one record.
     *
     * An editable cell is a write endpoint wearing a table's clothes, so it
     * is held to every rule a form is. The request names a resource, a
     * record, a column, and a value; the column is looked up in the schema
     * that declared it, and one that is not an `EditableColumn` does not
     * exist here however the request spells it. The value is validated
     * against the column's own rules, the record is authorized individually,
     * and a cell the column reports as disabled for *that record* is refused
     * — a disabled control is not a permission.
     */
    public function cell(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'resource' => ['required', 'string'],
            'record' => ['required'],
            'column' => ['required', 'string'],
        ]);

        $panel = $this->currentPanel();
        $resource = $this->resolveResource($panel, (string) $validated['resource']);

        $this->bindParentRecord($request, $resource);

        $column = $this->schemaFor($resource)->getColumn((string) $validated['column']);

        abort_if($column === null, 404, __('panda-panel::errors.unknown_column'));
        abort_unless($column instanceof EditableColumn, 400, __('panda-panel::errors.column_not_editable'));

        $key = $validated['record'];

        abort_unless(is_string($key) || is_int($key), 422, __('panda-panel::errors.invalid_record_key'));

        $model = $resource::findRecord($key);

        abort_if($model === null, 404);
        abort_unless($resource::canEdit($model), 403);
        abort_if($column->isDisabledFor($model), 403, __('panda-panel::errors.cell_not_editable'));

        $data = validator(
            ['value' => $request->input('value')],
            ['value' => $column->validationRules()],
        )->validate();

        DatabaseTransaction::run(null, static function () use ($column, $model, $data): void {
            $column->write($model, $data['value']);
        });

        return back()->with('success', 'Saved.');
    }

    /**
     * Runs a table-level action: one from the header, the toolbar, or the
     * empty state.
     *
     * No record, because these act on the table rather than on a row — so
     * authorization is asked with null, the way a bulk action's is before
     * anything is selected. The action is still looked up in the schema that
     * declared it, so one the table never offered does not exist.
     */
    public function table(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'resource' => ['required', 'string'],
            'action' => ['required', 'string'],
        ]);

        $panel = $this->currentPanel();
        $resource = $this->resolveResource($panel, (string) $validated['resource']);

        $this->bindParentRecord($request, $resource);

        $action = $this->schemaFor($resource)->getTableAction((string) $validated['action']);

        abort_if($action === null, 404, __('panda-panel::errors.unknown_action'));
        abort_unless($action->isTableExecutable(), 400, __('panda-panel::errors.action_not_executable'));
        abort_unless($action->isAuthorizedFor(null), 403);

        $action->executeWithoutRecord();

        return back()->with('success', $action->getSuccessMessage());
    }

    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'resource' => ['required', 'string'],
            'action' => ['required', 'string'],
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*' => ['required'],
        ]);

        $panel = $this->currentPanel();
        $resource = $this->resolveResource($panel, (string) $validated['resource']);

        $this->bindParentRecord($request, $resource);

        $schema = $this->schemaFor($resource);

        $action = $schema->getBulkAction((string) $validated['action']);

        abort_if($action === null, 404, __('panda-panel::errors.unknown_bulk_action'));
        abort_unless($action->isBulkExecutable() || $action->isExecutable(), 400, __('panda-panel::errors.action_not_executable'));
        abort_unless($action->isAuthorizedFor(null), 403);

        $keys = $this->scalarKeys($validated['records']);

        abort_if($keys === [], 422, __('panda-panel::errors.invalid_record_keys'));

        $records = $resource::findRecords($keys);

        // Keys outside the resource scope silently disappear here, so the
        // count check is what turns that into a visible failure rather than
        // a partial bulk operation.
        abort_if($records->count() !== count($keys), 404, __('panda-panel::errors.records_not_found'));

        $action->executeBulk($records);

        return back()->with('success', $action->getSuccessMessage());
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

    /**
     * Binds the parent a nested resource is scoped to.
     *
     * This endpoint is one per panel and carries no `{parentRecord}` segment,
     * so the scope a nested resource's pages get from route middleware has to
     * be established from the payload here. Resolution and authorization are
     * the parent resource's own, exactly as the middleware does it — without
     * this, an action on a nested resource would run against every parent's
     * children at once.
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
     * @param  class-string<PanelResource>  $resource
     */
    private function schemaFor(string $resource): TableSchema
    {
        return $resource::table(TableSchema::make());
    }
}
