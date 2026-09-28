<?php

declare(strict_types=1);

namespace PandaPanel\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Actions\Enums\ModalWidth;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Actions\Exports\ExportColumn;
use PandaPanel\Actions\Exports\Exporter;
use PandaPanel\Actions\Exports\ExportRun;
use PandaPanel\Core\PanelManager;
use PandaPanel\Forms\Components\CheckboxList;
use PandaPanel\Forms\Components\Radio;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Jobs\RunPanelExport;
use PandaPanel\Notifications\Notification;
use PandaPanel\Notifications\NotificationAction;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\TableState;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;

/**
 * Downloads the records as a spreadsheet.
 *
 * Two shapes that share everything but which records they cover: `make()`
 * exports the list as it is currently filtered, and `bulk()` exports the
 * selection. Both open the same dialog — pick the columns, pick the format —
 * because "which columns" is a question about the file, not about how the
 * records were chosen.
 *
 * Small exports run in the request. Large ones are queued and arrive as a
 * notification with a link, and the threshold is the exporter's own
 * (`queueAfter()`): a small export in a background job is a worse experience
 * than the wait it avoided.
 */
final class ExportAction
{
    /**
     * The whole list, as currently filtered.
     *
     * @param  class-string<Exporter>  $exporter
     * @param  class-string<PanelResource>  $resource
     */
    public static function make(string $exporter, string $resource): Action
    {
        return self::configure(Action::make('export'), $exporter)
            ->authorize(static fn (): bool => $resource::canViewAny())
            ->tableAction(static function (array $data) use ($exporter, $resource): void {
                self::run($exporter, $resource, $data, null);
            });
    }

    /**
     * The selection only.
     *
     * @param  class-string<Exporter>  $exporter
     * @param  class-string<PanelResource>  $resource
     */
    public static function bulk(string $exporter, string $resource): Action
    {
        return self::configure(Action::make('export'), $exporter)
            ->authorize(static fn (): bool => $resource::canViewAny())
            ->bulkAction(static function (Collection $records, array $data) use ($exporter, $resource): void {
                self::run($exporter, $resource, $data, array_values($records->modelKeys()));
            });
    }

    /**
     * @param  class-string<Exporter>  $exporter
     */
    private static function configure(Action $action, string $exporter): Action
    {
        return $action
            ->label(__('panda-panel::actions.export.label'))
            ->icon('download')
            ->variant(ActionVariant::Outline)
            ->modalHeading(__('panda-panel::actions.export.modal_heading'))
            ->modalSubmitLabel(__('panda-panel::actions.export.submit'))
            ->modalWidth(ModalWidth::Large)
            ->successMessage(__('panda-panel::actions.export.success'))
            ->schema(static fn (): FormSchema => self::form($exporter));
    }

    /**
     * The dialog: which columns, and which format.
     *
     * Both are fields rather than settings on the action, so they validate
     * against the exporter's own declarations — a column name that is not one
     * of its columns is refused by the same `in:` rule any other choice field
     * uses.
     *
     * @param  class-string<Exporter>  $exporter
     */
    private static function form(string $exporter): FormSchema
    {
        $options = [];
        $default = [];

        foreach ($exporter::columns() as $column) {
            $options[$column->getName()] = $column->getLabel();

            if ($column->isEnabledByDefault()) {
                $default[] = $column->getName();
            }
        }

        $formats = [];

        foreach ($exporter::formats() as $format) {
            $formats[$format->value] = $format->label();
        }

        return FormSchema::make()->schema([
            CheckboxList::make('columns')
                ->label(__('panda-panel::actions.export.columns'))
                ->options($options)
                ->columns(2)
                ->bulkToggleable()
                ->required()
                ->default($default),
            Radio::make('format')
                ->label(__('panda-panel::actions.export.format'))
                ->options($formats)
                ->inline()
                ->required()
                ->default(array_key_first($formats)),
        ]);
    }

    /**
     * @param  class-string<Exporter>  $exporter
     * @param  class-string<PanelResource>  $resource
     * @param  array<string, mixed>  $data
     * @param  list<int|string>|null  $keys
     */
    private static function run(string $exporter, string $resource, array $data, ?array $keys): void
    {
        $user = Auth::user();

        abort_if($user === null, 403);

        $owner = $user->getAuthIdentifier();

        abort_unless(is_int($owner) || is_string($owner), 500, __('panda-panel::errors.no_export_owner'));

        $panel = app(PanelManager::class)->currentPanel();

        abort_if($panel === null, 500, __('panda-panel::errors.no_panel'));

        $columns = self::columns($exporter, $data);
        $format = SpreadsheetFormat::tryFrom((string) ($data['format'] ?? '')) ?? SpreadsheetFormat::Csv;
        $state = TableState::fromRequest();

        // Counted before anything is written, because the count is what
        // decides whether this waits or is handed to a worker.
        $count = $keys === null
            ? self::query($resource, $state, null)->count()
            : count($keys);

        if ($exporter::queueAfter() >= 0 && $count > $exporter::queueAfter()) {
            RunPanelExport::dispatch(
                $exporter,
                $resource,
                $columns,
                $format,
                $owner,
                $state,
                $keys,
                $panel->getId(),
            );

            return;
        }

        $result = ExportRun::write(
            $exporter,
            self::query($resource, $state, $keys),
            $columns,
            $format,
            $owner,
        );

        $url = route($panel->routeName('export-file'), [
            'file' => $result['file'],
            'exporter' => $exporter,
        ], absolute: false);

        // Persisted so the file is findable later, but not broadcast: the
        // response carrying the toast is right here, and pushing the same
        // message over a websocket would show it twice.
        Notification::make('export-ready')
            ->title($exporter::completedMessage($result['records']))
            ->success()
            ->icon('download')
            ->persistent()
            ->broadcast(false)
            ->actions([
                NotificationAction::make('download')->label(__('panda-panel::actions.export.download'))->url($url),
            ])
            ->send($user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $exporter::completedMessage($result['records']),
            'url' => $url,
            'urlLabel' => __('panda-panel::actions.export.download'),
        ]);
    }

    /**
     * The records the file will hold.
     *
     * An explicit selection, or the list as the table state describes it —
     * through the same `TableQuery` the list itself uses, so the file and the
     * screen cannot disagree about which records those are.
     *
     * @param  class-string<PanelResource>  $resource
     * @param  array<string, mixed>  $state
     * @param  list<int|string>|null  $keys
     * @return Builder<covariant Model>
     */
    private static function query(string $resource, array $state, ?array $keys): Builder
    {
        $query = $resource::query();

        if ($keys !== null) {
            return $query->whereKey($keys);
        }

        (new TableQuery(
            $resource::table(TableSchema::make()),
            Request::create('/', 'GET', $state),
        ))->constrain($query);

        return $query;
    }

    /**
     * @param  class-string<Exporter>  $exporter
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function columns(string $exporter, array $data): array
    {
        $names = array_map(
            static fn (ExportColumn $column): string => $column->getName(),
            $exporter::columns(),
        );

        $chosen = $data['columns'] ?? [];

        if (! is_array($chosen)) {
            return $names;
        }

        $columns = [];

        foreach ($chosen as $name) {
            if (is_string($name) && in_array($name, $names, true)) {
                $columns[] = $name;
            }
        }

        // An empty choice is every column rather than an empty file: a
        // spreadsheet with a header and nothing else is not what anybody
        // meant by "export".
        return $columns === [] ? $names : $columns;
    }
}
