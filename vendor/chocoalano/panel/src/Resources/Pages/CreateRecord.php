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
use PandaPanel\Support\Breadcrumb;
use PandaPanel\Support\DatabaseTransaction;
use PandaPanel\Support\FormEndpoints;

/**
 * The create page of a resource.
 *
 * `handle()` runs the documented create lifecycle. Only fields declared in
 * the schema are validated, and only fields that dehydrate are persisted, so
 * an extra key in the request body is discarded rather than mass-assigned.
 */
abstract class CreateRecord extends ResourcePage
{
    protected static string $component = 'panel/resources/Create';

    protected static string $page = 'create';

    /**
     * Whether the form offers to save and start another.
     */
    protected static bool $canCreateAnother = true;

    /**
     * Whether starting another keeps what was just typed.
     *
     * Off by default: the common case is a run of different records, and a
     * form that silently keeps the previous one invites saving it twice.
     */
    protected static bool $preservesDataOnCreateAnother = false;

    public function render(Request $request): Response
    {
        $resource = static::$resource;

        abort_unless($resource::canCreate(), 403);

        try {
            $form = $this->fillForm($this->schema());
        } catch (Halt) {
            return $this->haltedRender();
        }

        // Set by a previous "create another" when the page preserves data.
        $form['schema'] = $this->applyPreservedInput($form['schema'], $request);

        return Inertia::render(static::$component, [
            'page' => $this->pageMetadata(),
            'resource' => $this->resourceMetadata(),
            'form' => $form,
            'submitUrl' => $resource::url('store'),
            'optionsUrl' => FormEndpoints::forResource($resource, static::$page),
            'uploadUrl' => FormEndpoints::upload($resource, static::$page),
            'formStateUrl' => FormEndpoints::formState($resource, static::$page),
            // Null keeps whatever the page already said — see
            // `ResourcePage::$submitLabel`.
            'submitLabel' => $this->getSubmitLabel(),
            // Present only for a stepped form; the wizard asks here before
            // moving on.
            'validateStepUrl' => $this->schema()->wizard() === null
                ? null
                : $resource::url('validateCreateStep'),
            ...$this->widgetProps(),
            'canCreateAnother' => static::$canCreateAnother,
        ]);
    }

    public function handle(Request $request): RedirectResponse
    {
        $resource = static::$resource;

        abort_unless($resource::canCreate(), 403);

        $schema = $this->schema();

        try {
            $record = $this->create($request, $schema);
        } catch (Halt) {
            // Halting is a decision, not a failure: back where they were,
            // with whatever the hook chose to say already flashed.
            return back();
        }

        return $this->createAnotherRequested($request)
            ? $this->redirectToAnother($request, $record)
            : $this->notify(redirect()->to($this->getRedirectUrl($record)), $record);
    }

    public function validateStep(Request $request): JsonResponse
    {
        abort_unless(static::$resource::canCreate(), 403);

        return $this->validateStepFor($request, $this->schema());
    }

    /**
     * The create lifecycle, in the order `HasLifecycleHooks` documents.
     */
    private function create(Request $request, FormSchema $schema): Model
    {
        $input = $this->beforeValidate($request->all());

        $data = $this->afterValidate(
            validator($input, $schema->validationRules())->validate(),
        );

        $this->beforeCreate();

        $data = $this->mutateFormDataBeforeSave(
            $this->mutateFormDataBeforeCreate($data),
            null,
        );

        $this->beforeSave(null);

        $attributes = $schema->dehydrate($data);

        return DatabaseTransaction::run(static::$hasDatabaseTransactions, function () use ($schema, $attributes, $data): Model {
            $record = $this->handleRecordCreation($attributes);

            // Related records and pivot rows need a key that did not exist
            // until the line above, so they are written here rather than with
            // the attributes — and inside the same transaction, so a record
            // never survives without the relations it was submitted with.
            $schema->saveRelations($record, $data);

            $this->afterCreate($record);
            $this->afterSave($record);

            return $record;
        });
    }

    /**
     * The write itself. Override to create through a service, a factory, or
     * a relationship rather than a bare save.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function handleRecordCreation(array $attributes): Model
    {
        $model = static::$resource::getModel();

        /** @var Model $record */
        $record = new $model;

        $record->forceFill($attributes)->save();

        return $record;
    }

    /**
     * Where the user lands once the record exists. Override for a page that
     * should continue somewhere else entirely.
     */
    protected function getRedirectUrl(Model $record): string
    {
        $resource = static::$resource;

        return array_key_exists('edit', $resource::pages())
            ? $resource::url('edit', $record)
            : $resource::url();
    }

    /**
     * What is said afterwards. Return null for a page that should say
     * nothing.
     *
     * @return array{type: string, message: string}|null
     */
    protected function createdNotification(Model $record): ?array
    {
        return [
            'type' => 'success',
            'message' => sprintf('%s created.', static::$resource::label()),
        ];
    }

    private function createAnotherRequested(Request $request): bool
    {
        return static::$canCreateAnother && $request->boolean('createAnother');
    }

    /**
     * Back to an empty create form, or to the same values when the page
     * preserves them.
     */
    private function redirectToAnother(Request $request, Model $record): RedirectResponse
    {
        $redirect = redirect()->to(static::$resource::url('create'));

        if (static::$preservesDataOnCreateAnother) {
            $redirect->withInput($request->except(['createAnother', '_token']));
        }

        return $this->notify($redirect, $record);
    }

    private function notify(RedirectResponse $redirect, Model $record): RedirectResponse
    {
        $notification = $this->createdNotification($record);

        return $notification === null
            ? $redirect
            : $redirect->with($notification['type'], $notification['message']);
    }

    /**
     * A halt while rendering means the page decided not to be shown.
     */
    private function haltedRender(): Response
    {
        abort(redirect()->to(static::$resource::url()));
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return list<array<string, mixed>>
     */
    private function applyPreservedInput(array $components, Request $request): array
    {
        $old = $request->old();

        return is_array($old) && $old !== []
            ? $this->fillWithOldInput($components, $old)
            : $components;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @param  array<string, mixed>  $old
     * @return list<array<string, mixed>>
     */
    private function fillWithOldInput(array $components, array $old): array
    {
        foreach ($components as $index => $component) {
            if (($component['component'] ?? null) === 'field') {
                if (array_key_exists($component['name'], $old)) {
                    $components[$index]['value'] = $old[$component['name']];
                }

                continue;
            }

            if (isset($component['schema'])) {
                $components[$index]['schema'] = $this->fillWithOldInput($component['schema'], $old);
            }
        }

        return $components;
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
        return 'New '.static::$resource::label();
    }

    /**
     * @return array<string, mixed>
     */
    protected function pageMetadata(): array
    {
        return [
            ...$this->headingMetadata(),
            'breadcrumbs' => $this->serializeBreadcrumbs([
                ...$this->baseBreadcrumbs(),
                Breadcrumb::make('New')->current(),
            ]),
            'headerActions' => [],
            'scope' => static::renderHookScope(),
            'cluster' => $this->clusterNavigation(),
        ];
    }
}
