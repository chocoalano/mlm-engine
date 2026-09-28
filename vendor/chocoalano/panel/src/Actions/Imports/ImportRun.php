<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Imports;

use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Support\Spreadsheet\Csv;
use PandaPanel\Support\Spreadsheet\SpreadsheetException;
use PandaPanel\Support\Spreadsheet\Xlsx;

/**
 * Reads one import file and writes what it can.
 *
 * The same code whether it runs in the request or in a queued job, for the
 * reason `ExportRun` is: an import that behaved differently once the file got
 * big would be a bug nobody found until it mattered.
 *
 * A row that fails is collected, not thrown. An import of a thousand rows
 * where the four-hundredth has a bad date should import nine hundred and
 * ninety-nine and tell you about the one — stopping at the first mistake
 * turns a five-minute correction into a re-upload.
 *
 * Each row is its own transaction. One bad row must not undo the good rows
 * before it, and a row that creates a related record and then fails must not
 * leave that record behind.
 */
final class ImportRun
{
    /**
     * @param  class-string<Importer>  $importer
     * @param  array<string, int>  $mapping  column name => position in the file
     * @return array{imported: int, failed: int, report: string|null}
     */
    public static function run(
        string $importer,
        string $path,
        array $mapping,
        int|string $owner,
    ): array {
        $columns = self::columns($importer);
        $model = $importer::model();
        $blank = new $model;

        $imported = 0;
        $failures = [];

        foreach (self::rows($importer, $path) as $number => $cells) {
            $result = self::row($importer, $columns, $blank, $cells, $mapping);

            if ($result === null) {
                $imported++;

                continue;
            }

            // The row as it was, plus why it was refused — so the report can
            // be corrected and re-uploaded as it stands.
            $failures[] = [...$cells, $result];
        }

        return [
            'imported' => $imported,
            'failed' => count($failures),
            'report' => $failures === []
                ? null
                : self::report($importer, $path, $failures, $owner),
        ];
    }

    /**
     * The header row, for the mapping step.
     *
     * Only the first row is read, which is what makes asking "what is in this
     * file" cheap enough to do while the user waits.
     *
     * @return list<string>
     */
    public static function headings(string $path): array
    {
        foreach (self::read($path) as $row) {
            return $row;
        }

        return [];
    }

    /**
     * How many rows the file holds, not counting the header.
     *
     * Read rather than estimated, because it decides whether the import waits
     * or is handed to a worker — and a guess would put a large file in the
     * request or a small one behind a queue nobody is watching.
     */
    public static function countRows(string $path): int
    {
        $rows = 0;

        foreach (self::read($path) as $ignored) {
            $rows++;
        }

        return max($rows - 1, 0);
    }

    /**
     * Where each declared column sits in the file, guessed from the headings.
     *
     * A column with no match is absent from the result rather than pointing
     * at position zero — the mapping step then shows it unmapped instead of
     * importing the first column into it.
     *
     * @param  class-string<Importer>  $importer
     * @param  list<string>  $headings
     * @return array<string, int>
     */
    public static function guessMapping(string $importer, array $headings): array
    {
        $normalized = array_map(
            static fn (string $heading): string => mb_strtolower(trim($heading)),
            $headings,
        );

        $mapping = [];

        foreach ($importer::columns() as $column) {
            foreach ($column->headings() as $candidate) {
                $position = array_search($candidate, $normalized, true);

                if ($position !== false) {
                    $mapping[$column->getName()] = $position;

                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * The required columns the file has no heading for.
     *
     * Without this, an unmapped required column produced a validation failure
     * on *every row* — "The name field is required", ten thousand times — which
     * is a true statement about the wrong thing. The file does not have a name
     * column at all, and saying so once, before the import starts, is the
     * difference between a fixable message and a wall of them.
     *
     * @param  class-string<Importer>  $importer
     * @param  array<string, int>  $mapping
     * @return list<string>
     */
    public static function unmappedRequiredColumns(string $importer, array $mapping): array
    {
        $missing = [];

        foreach ($importer::columns() as $column) {
            if ($column->isRequired() && ! array_key_exists($column->getName(), $mapping)) {
                $missing[] = $column->getName();
            }
        }

        return $missing;
    }

    /**
     * Says which required columns the file is missing, and what it does have.
     *
     * @param  list<string>  $missing
     * @param  list<string>  $headings
     */
    public static function missingColumnsMessage(array $missing, array $headings): string
    {
        return __('panda-panel::actions.import.missing_columns', [
            'missing' => '['.implode('], [', $missing).']',
            // A separate choice string rather than a ternary, because the
            // number that decides between "it is" and "they are" does not
            // decide the same thing in every language.
            'verb' => trans_choice('panda-panel::actions.import.missing_columns_verb', count($missing)),
            'headings' => $headings === []
                ? __('panda-panel::actions.import.no_headings')
                : implode(', ', $headings),
        ]);
    }

    /**
     * One row: cast, resolve, validate, write.
     *
     * Returns null when the row was imported, or the reason it was not.
     *
     * @param  list<ImportColumn>  $columns
     * @param  list<string>  $cells
     * @param  array<string, int>  $mapping
     */
    private static function row(
        string $importer,
        array $columns,
        Model $blank,
        array $cells,
        array $mapping,
    ): ?string {
        try {
            return DB::transaction(static function () use ($importer, $columns, $blank, $cells, $mapping): ?string {
                $data = [];
                $rules = [];

                foreach ($columns as $column) {
                    $name = $column->getName();
                    $position = $mapping[$name] ?? null;
                    $raw = $position === null ? '' : ($cells[$position] ?? '');

                    $value = $column->cast($raw);

                    if ($column->getRelationship() !== null) {
                        $value = $column->resolveRelated($blank, $value);
                    }

                    $data[$name] = $value;
                    $rules[$name] = $column->validationRules();
                }

                $validator = Validator::make($data, [...$rules, ...$importer::rules()]);

                if ($validator->fails()) {
                    return implode(' ', $validator->errors()->all());
                }

                $record = $importer::resolve($data);

                if ($record === null) {
                    return null;
                }

                $attributes = [];

                foreach ($columns as $column) {
                    $attributes[$column->attribute($blank)] = $data[$column->getName()];
                }

                $record->forceFill($attributes)->save();

                return null;
            });
        } catch (\Throwable $exception) {
            // A row that threw is a failed row, not a failed import. The
            // message goes in the report beside the data that produced it.
            return $exception->getMessage();
        }
    }

    /**
     * Writes the rows that could not be imported, with a reason column.
     *
     * Always CSV, whatever the upload was: this is a file to correct and
     * re-upload, and a CSV opens everywhere.
     *
     * @param  class-string<Importer>  $importer
     * @param  list<list<string>>  $failures
     */
    private static function report(
        string $importer,
        string $source,
        array $failures,
        int|string $owner,
    ): string {
        $temporary = tempnam(sys_get_temp_dir(), 'panel-import-failures-');

        if ($temporary === false) {
            throw new SpreadsheetException(__('panda-panel::errors.spreadsheet.report_unwritable'));
        }

        $handle = Csv::open($temporary);

        Csv::write($handle, [...self::headings($source), __('panda-panel::actions.import.error_heading')]);

        foreach ($failures as $row) {
            Csv::write($handle, $row);
        }

        fclose($handle);

        $path = $importer::directory().'/'.$owner.'/failed-rows-'.date('Y-m-d-His').'.csv';

        $stream = fopen($temporary, 'rb');

        if ($stream === false) {
            throw new SpreadsheetException(__('panda-panel::errors.spreadsheet.report_unreadable'));
        }

        Storage::disk($importer::disk())->put($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        unlink($temporary);

        return basename($path);
    }

    /**
     * Every row but the header.
     *
     * @param  class-string<Importer>  $importer
     * @return Generator<int, list<string>>
     */
    private static function rows(string $importer, string $path): Generator
    {
        $first = true;

        foreach (self::read($path) as $index => $row) {
            if ($first) {
                $first = false;

                continue;
            }

            yield $index => $row;
        }
    }

    /**
     * @return Generator<int, list<string>>
     */
    private static function read(string $path): Generator
    {
        return SpreadsheetFormat::fromPath($path) === SpreadsheetFormat::Xlsx
            ? Xlsx::read($path)
            : Csv::read($path);
    }

    /**
     * @param  class-string<Importer>  $importer
     * @return list<ImportColumn>
     */
    private static function columns(string $importer): array
    {
        return $importer::columns();
    }
}
