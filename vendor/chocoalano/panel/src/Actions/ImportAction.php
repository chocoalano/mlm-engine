<?php

declare(strict_types=1);

namespace PandaPanel\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Actions\Enums\ModalWidth;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Actions\Imports\Importer;
use PandaPanel\Actions\Imports\ImportRun;
use PandaPanel\Actions\Support\Modal;
use PandaPanel\Core\PanelManager;
use PandaPanel\Forms\Components\FileUpload;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Section;
use PandaPanel\Jobs\RunPanelImport;
use PandaPanel\Notifications\Notification;
use PandaPanel\Notifications\NotificationAction;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * Loads records from a spreadsheet.
 *
 * Two steps in one dialog rather than two dialogs: the file is uploaded by
 * the ordinary `FileUpload` field, which stores it before the form is
 * submitted, and the submit says which column of the file feeds which column
 * of the import. That ordering is what makes mapping possible at all — the
 * headings cannot be offered until the file exists.
 *
 * Small files are read in the request. Large ones are queued and the result
 * arrives as a notification, with a link to the rows that could not be
 * imported. A partial import is the intended outcome, not a failure: one bad
 * date in row four hundred should not cost the other nine hundred and
 * ninety-nine.
 */
final class ImportAction
{
    /**
     * What a mapping field is named.
     *
     * A flat prefix rather than `mapping.column`: a dotted name is nested
     * data by the time it reaches the server, and only a relation group knows
     * how to read one of those back. A prefix keeps the mapping fields
     * ordinary fields.
     */
    private const MAPPING_PREFIX = 'map_';

    /**
     * @param  class-string<Importer>  $importer
     * @param  class-string<PanelResource>  $resource
     */
    public static function make(string $importer, string $resource): Action
    {
        return Action::make('import')
            ->label(__('panda-panel::actions.import.label'))
            ->icon('upload')
            ->variant(ActionVariant::Outline)
            ->modalHeading(__('panda-panel::actions.import.modal_heading'))
            ->modalSubmitLabel(__('panda-panel::actions.import.submit'))
            ->modalWidth(ModalWidth::Large)
            ->modal(static function (Modal $modal): void {
                $modal->description(__('panda-panel::actions.import.description'));

                // A long dialog with an upload in it is exactly where a stray
                // click outside costs the most.
                $modal->closeByClickingAway(false);
            })
            ->authorize(static fn (): bool => $resource::canCreate())
            ->schema(static fn (): FormSchema => self::form($importer))
            ->tableAction(static function (array $data) use ($importer): void {
                self::run($importer, $data);
            });
    }

    /**
     * The file, then one select per column.
     *
     * The mapping selects are `live()`-free on purpose: their options are the
     * spreadsheet's own headings, which are not known until a file has been
     * uploaded. Rather than a round trip per keystroke, a column left unmapped
     * is simply not imported — which is the same thing a file missing that
     * column would mean.
     *
     * @param  class-string<Importer>  $importer
     */
    private static function form(string $importer): FormSchema
    {
        $mapping = [];

        foreach ($importer::columns() as $column) {
            $mapping[] = Select::make(self::MAPPING_PREFIX.$column->getName())
                ->label($column->getLabel())
                ->options(self::positions())
                // Two hundred options is a scroll, not a list. Searchable
                // turns "which one is BC again" into typing it.
                ->searchable()
                ->helperText($column->isRequired() ? __('panda-panel::actions.import.required') : null);
        }

        return FormSchema::make()->schema([
            FileUpload::make('file')
                ->label(__('panda-panel::actions.import.file'))
                ->disk($importer::disk())
                ->directory($importer::directory())
                ->acceptedTypes(array_merge(
                    SpreadsheetFormat::Csv->mimeTypes(),
                    SpreadsheetFormat::Xlsx->mimeTypes(),
                ))
                ->maxSize(20480)
                ->required(),
            Section::make(__('panda-panel::actions.import.columns_section'))
                ->description(__('panda-panel::actions.import.mapping_hint'))
                ->columns(2)
                ->schema($mapping),
        ]);
    }

    /**
     * How many columns deep the mapping select goes.
     *
     * A bound rather than "as many as the file has", because the form is
     * built before any file has been uploaded — the select is the manual
     * override for what `guessMapping()` could not work out from the
     * headings, and it has to be drawable before there is a file to measure.
     *
     * Two hundred is past the width of every spreadsheet anybody imports by
     * hand and cheap to render behind a searchable select. A file wider than
     * this still imports: heading matching has no bound at all, so a column
     * at position 300 is mapped automatically as long as its heading is
     * recognisable.
     */
    private const MAX_COLUMNS = 200;

    /**
     * The column positions a person can choose between.
     *
     * Letters rather than numbers, because that is what a spreadsheet shows
     * in its own column headers — "C" is findable in the file, "2" is not.
     *
     * @return array<string, string>
     */
    private static function positions(): array
    {
        $options = [];

        foreach (range(0, self::MAX_COLUMNS - 1) as $index) {
            // Keyed as a string so the option list is a map rather than a
            // list — `0` as an integer key would make this an array whose
            // first option has no value.
            $options['col'.$index] = self::columnLabel($index);
        }

        return $options;
    }

    /**
     * A zero-based column index as a spreadsheet spells it: A, B, … Z, AA, AB.
     *
     * This used to be `chr(65 + $index)`, which is correct for exactly the
     * first twenty-six columns and silently wrong after that — column 26
     * came out as `[`, and there was no way to select it at all because the
     * list stopped at Z. Any file with more than twenty-six columns had its
     * later columns unmappable by hand.
     *
     * Base-26 with no zero digit, which is what makes it fiddly: the
     * sequence runs A–Z then AA, so each step subtracts one before dividing
     * rather than after.
     */
    private static function columnLabel(int $index): string
    {
        $label = '';

        for ($remaining = $index; $remaining >= 0; $remaining = intdiv($remaining, 26) - 1) {
            $label = chr(65 + $remaining % 26).$label;
        }

        return $label;
    }

    /**
     * @param  class-string<Importer>  $importer
     * @param  array<string, mixed>  $data
     */
    private static function run(string $importer, array $data): void
    {
        $user = Auth::user();

        abort_if($user === null, 403);

        $owner = $user->getAuthIdentifier();

        abort_unless(is_int($owner) || is_string($owner), 500, __('panda-panel::errors.no_import_owner'));

        $panel = app(PanelManager::class)->currentPanel();

        abort_if($panel === null, 500, __('panda-panel::errors.no_panel'));

        $stored = $data['file'] ?? null;

        abort_unless(is_string($stored) && $stored !== '', 422, __('panda-panel::errors.no_file_uploaded'));

        $disk = Storage::disk($importer::disk());

        abort_unless($disk->exists($stored), 404, __('panda-panel::errors.file_gone'));

        // Both readers need a real path: a CSV streams from a handle and an
        // XLSX is a zip opened by name. A remote disk gets a local copy for
        // the length of the read.
        $local = $disk->path($stored);

        $headings = ImportRun::headings($local);
        $mapping = self::mapping($importer, $data, $headings);

        // Before a single row is read. A required column with no heading fails
        // every row identically, which is ten thousand true statements about
        // the wrong thing — the file simply does not have that column.
        $missing = ImportRun::unmappedRequiredColumns($importer, $mapping);

        if ($missing !== []) {
            $disk->delete($stored);

            throw ValidationException::withMessages([
                'file' => ImportRun::missingColumnsMessage($missing, $headings),
            ]);
        }

        $rows = ImportRun::countRows($local);

        if ($importer::queueAfter() >= 0 && $rows > $importer::queueAfter()) {
            RunPanelImport::dispatch($importer, $stored, $mapping, $owner, $panel->getId());

            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('panda-panel::actions.import.started'),
            ]);

            return;
        }

        $result = ImportRun::run($importer, $local, $mapping, $owner);

        $disk->delete($stored);

        $url = $result['report'] === null ? null : route($panel->routeName('import-file'), [
            'file' => $result['report'],
            'importer' => $importer,
        ], absolute: false);

        // Only when something failed. A clean import is answered by the toast
        // and nothing else — a bell that fills up with "imported 40 rows" is
        // a bell nobody reads.
        if ($url !== null) {
            Notification::make('import-finished')
                ->title($importer::completedMessage($result['imported'], $result['failed']))
                ->warning()
                ->persistent()
                ->broadcast(false)
                ->actions([
                    NotificationAction::make('failed-rows')
                        ->label(__('panda-panel::actions.import.download_failed_rows'))
                        ->url($url),
                ])
                ->send($user);
        }

        Inertia::flash('toast', [
            'type' => $result['failed'] === 0 ? 'success' : 'warning',
            'message' => $importer::completedMessage($result['imported'], $result['failed']),
            'url' => $url,
            'urlLabel' => __('panda-panel::actions.import.download_failed_rows'),
        ]);
    }

    /**
     * What the user chose, filled in from the headings for anything they left
     * blank.
     *
     * Guessing only where nothing was said is the important part: an explicit
     * choice is never overridden by a heading that happens to look right.
     *
     * @param  class-string<Importer>  $importer
     * @param  array<string, mixed>  $data
     * @param  list<string>  $headings
     * @return array<string, int>
     */
    private static function mapping(string $importer, array $data, array $headings): array
    {
        $mapping = ImportRun::guessMapping($importer, $headings);

        foreach ($importer::columns() as $column) {
            $chosen = $data[self::MAPPING_PREFIX.$column->getName()] ?? null;

            if (is_string($chosen) && str_starts_with($chosen, 'col')) {
                $mapping[$column->getName()] = (int) mb_substr($chosen, 3);
            }
        }

        return $mapping;
    }
}
