<?php

declare(strict_types=1);

namespace PandaPanel\Resources\Pages;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PandaPanel\Exceptions\Halt;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Concerns\InteractsWithRecord;
use PandaPanel\Support\Breadcrumb;
use PandaPanel\Support\DatabaseTransaction;
use PandaPanel\Support\FormEndpoints;
use PandaPanel\Widgets\PageContext;

/**
 * The edit page of a resource.
 *
 * The record is always resolved through `Resource::query()`, so a record the
 * resource scope excludes is a 404 here as well as on the index.
 */
abstract class EditRecord extends ResourcePage
{
    protected static string $component = 'panel/resources/Edit';

    use InteractsWithRecord;

    protected static string $page = 'edit';

    public function render(Request $request, ?string $record = null): Response
    {
        $resource = static::$resource;
        $model = $this->resolveRecord($record);

        try {
            $form = $this->fillForm($this->schema(), $model);
        } catch (Halt) {
            abort(redirect()->to($resource::url()));
        }

        return Inertia::render(static::$component, [
            'page' => $this->pageMetadata($model),
            'resource' => $this->resourceMetadata(),
            'form' => $form,
            'submitUrl' => $resource::url('update', $model),
            'optionsUrl' => FormEndpoints::forResource($resource, static::$page, $model),
            'uploadUrl' => FormEndpoints::upload($resource, static::$page, $model),
            'formStateUrl' => FormEndpoints::formState($resource, static::$page, $model),
            // Null keeps "Save changes" — see `ResourcePage::$submitLabel`.
            'submitLabel' => $this->getSubmitLabel(),
            'validateStepUrl' => $this->schema()->wizard() === null
                ? null
                : $resource::url('validateEditStep', $model),
            ...$this->widgetProps(PageContext::forRecord($model)),
            'recordKey' => $model->getKey(),
            'relations' => $this->relationTables($request, $model),
        ]);
    }

    public function handle(Request $request, ?string $record = null): RedirectResponse
    {
        $model = $this->resolveRecord($record);

        $schema = $this->schema();

        try {
            $this->save($request, $schema, $model);
        } catch (Halt) {
            return back();
        }

        $redirect = redirect()->to($this->getRedirectUrl($model));
        $notification = $this->savedNotification($model);

        return $notification === null
            ? $redirect
            : $redirect->with($notification['type'], $notification['message']);
    }

    public function validateStep(Request $request, ?string $record = null): JsonResponse
    {
        // Resolving authorizes with `canEdit()`, so a step cannot be
        // validated against a record the user may not edit.
        $model = $this->resolveRecord($record);

        return $this->validateStepFor($request, $this->schema(), $model);
    }

    /**
     * The update lifecycle, in the order `HasLifecycleHooks` documents.
     */
    private function save(Request $request, FormSchema $schema, Model $model): void
    {
        $input = $this->beforeValidate($request->all());

        $data = $this->afterValidate(
            validator($input, $schema->validationRules($model))->validate(),
        );

        $data = $this->mutateFormDataBeforeSave($data, $model);

        $this->beforeSave($model);

        $attributes = $schema->dehydrate($data, $model);

        DatabaseTransaction::run(static::$hasDatabaseTransactions, function () use ($schema, $model, $attributes, $data): void {
            $record = $this->handleRecordUpdate($model, $attributes);

            // Related records and pivot rows are written here, inside the
            // same transaction, so an update never half-applies across the
            // record and the relations it was submitted with.
            $schema->saveRelations($record, $data);

            $this->afterSave($record);
        });
    }

    /**
     * Editing needs more than viewing, so this page asks for more.
     */
    protected function authorizeRecord(Model $record): bool
    {
        return static::$resource::canEdit($record);
    }

    /**
     * The write itself. Override to update through a service rather than a
     * bare save.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function handleRecordUpdate(Model $record, array $attributes): Model
    {
        $record->forceFill($attributes)->save();

        return $record;
    }

    /**
     * Where the user lands once the record is saved.
     */
    protected function getRedirectUrl(Model $record): string
    {
        return static::$resource::url('edit', $record);
    }

    /**
     * @return array{type: string, message: string}|null
     */
    protected function savedNotification(Model $record): ?array
    {
        return [
            'type' => 'success',
            'message' => sprintf('%s updated.', static::$resource::label()),
        ];
    }

    protected function schema(): FormSchema
    {
        $resource = static::$resource;

        return $resource::form(
            FormSchema::make()
                ->model($resource::getModel())
                ->forPage(static::$page),
        );
    }

    protected function defaultTitle(?Model $record): string
    {
        return $record === null
            ? 'Edit '.static::$resource::label()
            : 'Edit '.$this->recordTitle($record);
    }

    /**
     * The heading is the record, not "Edit {record}": the breadcrumb above it
     * already says which page this is, and repeating the verb twice reads as
     * a mistake.
     */
    protected function defaultHeading(?Model $record): string
    {
        return $record === null
            ? static::$resource::label()
            : $this->recordTitle($record);
    }

    protected function defaultSubheading(?Model $record): ?string
    {
        return 'Edit '.static::$resource::label();
    }

    /**
     * @return array<string, mixed>
     */
    protected function pageMetadata(Model $record): array
    {
        $title = $this->recordTitle($record);

        return [
            ...$this->headingMetadata($record),
            'breadcrumbs' => $this->serializeBreadcrumbs([
                ...$this->baseBreadcrumbs(),
                $this->recordCrumb($record, $title),
                Breadcrumb::make('Edit')->current(),
            ]),
            'headerActions' => [],
            'scope' => static::renderHookScope(),
            'cluster' => $this->clusterNavigation(),
            'subNavigation' => $this->subNavigation($record, 'edit'),
        ];
    }
}
