<?php

declare(strict_types=1);

namespace PandaPanel\Resources\Pages;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\PasswordInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Resources\Concerns\InteractsWithRecord;
use PandaPanel\Support\Breadcrumb;
use PandaPanel\Widgets\PageContext;

/**
 * The read-only detail page of a resource.
 *
 * Values are derived from the same form schema the edit page uses, so a
 * field added once shows up in both places. Password fields are excluded:
 * they have nothing meaningful to display and rendering the stored hash
 * would put it on screen.
 */
abstract class ViewRecord extends ResourcePage
{
    protected static string $component = 'panel/resources/View';

    use InteractsWithRecord;

    protected static string $page = 'view';

    public function render(Request $request, ?string $record = null): Response
    {
        $model = $this->resolveRecord($record);

        $infolist = static::$resource::infolist(InfolistSchema::make());

        return Inertia::render(static::$component, [
            'page' => $this->pageMetadata($model),
            'resource' => $this->resourceMetadata(),
            ...$this->widgetProps(PageContext::forRecord($model)),
            'infolist' => $infolist->isEmpty() ? null : $infolist->toArray($model),
            // The form-derived fallback, for a resource that has not declared
            // an infolist yet.
            'entries' => $infolist->isEmpty() ? $this->entries($model) : [],
            'recordKey' => $model->getKey(),
            'actionEndpoints' => $this->actionEndpoints(),
            'relations' => $this->relationTables($request, $model),
        ]);
    }

    /**
     * @return list<array{label: string, value: string|null}>
     */
    protected function entries(Model $record): array
    {
        $resource = static::$resource;

        $schema = $resource::form(
            FormSchema::make()
                ->model($resource::getModel())
                ->forPage(static::$page),
        );

        $entries = [];

        foreach ($schema->fields() as $field) {
            if ($field instanceof PasswordInput) {
                continue;
            }

            $entries[] = [
                'label' => $field->getLabel(),
                'value' => $this->displayValue($field, $record),
            ];
        }

        return $entries;
    }

    /**
     * Values are stringified on the server so the page renders finished text
     * rather than reimplementing formatting per type in Vue.
     */
    protected function displayValue(Field $field, Model $record): ?string
    {
        $value = $field->formValue($record);

        return match (true) {
            $value === null || $value === '' => null,
            is_bool($value) => $value ? 'Yes' : 'No',
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    protected function defaultTitle(?Model $record): string
    {
        return $record === null
            ? static::$resource::label()
            : $this->recordTitle($record);
    }

    protected function defaultSubheading(?Model $record): ?string
    {
        return static::$resource::label();
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
                Breadcrumb::make($title)->current(),
            ]),
            'headerActions' => $this->headerActions($record),
            'scope' => static::renderHookScope(),
            'cluster' => $this->clusterNavigation(),
            'subNavigation' => $this->subNavigation($record, 'view'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function headerActions(Model $record): array
    {
        $resource = static::$resource;

        if (! array_key_exists('edit', $resource::pages()) || ! $resource::canEdit($record)) {
            return [];
        }

        return [[
            'name' => 'edit',
            'label' => 'Edit',
            'icon' => 'pencil',
            'variant' => ActionVariant::Default->value,
            'type' => 'link',
            'url' => $resource::url('edit', $record),
            'confirmation' => null,
        ]];
    }
}
