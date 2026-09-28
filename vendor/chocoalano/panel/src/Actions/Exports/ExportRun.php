<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Exports;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Support\EagerLoadPaths;
use PandaPanel\Support\Spreadsheet\Csv;
use PandaPanel\Support\Spreadsheet\Xlsx;

/**
 * Writes one export file.
 *
 * The same code whether it runs inside the request or inside a queued job —
 * that is the whole reason it is not a method on either. A queued export that
 * produced a different file from an immediate one would be a bug nobody found
 * until the row count crossed a threshold.
 *
 * Written to a temporary local file and then put on the disk, because both
 * writers need a real path: a CSV streams to a handle and an XLSX is a zip
 * that `ZipArchive` opens by name. Assembling either in memory to hand a
 * string to the filesystem would defeat the streaming.
 */
final class ExportRun
{
    /**
     * @param  class-string<Exporter>  $exporter
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $columns  the names the user chose, in schema order
     * @param  int|string  $owner  the key of the user the file belongs to
     * @return array{path: string, file: string, records: int}
     */
    public static function write(
        string $exporter,
        Builder $query,
        array $columns,
        SpreadsheetFormat $format,
        int|string $owner,
    ): array {
        $selected = self::selected($exporter, $columns);

        $temporary = tempnam(sys_get_temp_dir(), 'panel-export-');

        if ($temporary === false) {
            throw new \RuntimeException(__('panda-panel::errors.spreadsheet.export_temp_failed'));
        }

        $records = 0;
        $rows = self::rows($exporter, $query, $selected, $records);

        if ($format === SpreadsheetFormat::Xlsx) {
            Xlsx::write($temporary, $rows);
        } else {
            $handle = Csv::open($temporary);

            foreach ($rows as $row) {
                Csv::write($handle, $row, $exporter::escapesFormulas());
            }

            fclose($handle);
        }

        $file = $exporter::fileName().'.'.$format->extension();

        // Filed under the user it belongs to, and the download endpoint
        // builds that segment from whoever is asking rather than from the
        // request. One user cannot name another's export however they spell
        // the path.
        $path = $exporter::directory().'/'.$owner.'/'.$file;

        $stream = fopen($temporary, 'rb');

        if ($stream === false) {
            throw new \RuntimeException(__('panda-panel::errors.spreadsheet.export_unreadable'));
        }

        Storage::disk($exporter::disk())->put($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        unlink($temporary);

        return ['path' => $path, 'file' => $file, 'records' => $records];
    }

    /**
     * The header, then one row per record.
     *
     * A generator over a chunked query, so an export of a hundred thousand
     * records holds `chunkSize()` of them at a time and no more.
     *
     * @param  class-string<Exporter>  $exporter
     * @param  Builder<covariant Model>  $query
     * @param  list<ExportColumn>  $columns
     * @return Generator<int, list<string>>
     */
    private static function rows(
        string $exporter,
        Builder $query,
        array $columns,
        int &$records,
    ): Generator {
        yield array_map(
            static fn (ExportColumn $column): string => $column->getLabel(),
            $columns,
        );

        $source = $exporter::query($query);

        // The widest N+1 in the package, and the only one with no page size to
        // bound it. An export walks the whole result set, and an exporter is
        // free to export columns the table never shows — so `$with`, which was
        // sized for the list, does not cover them. A hundred thousand records
        // and one dotted column was a hundred thousand queries, chunked but
        // not fewer.
        //
        // Derived from the columns actually being written, so an unselected
        // column costs nothing.
        $paths = EagerLoadPaths::from(
            $source->getModel(),
            array_map(static fn (ExportColumn $column): string => $column->getName(), $columns),
        );

        if ($paths !== []) {
            $source->with($paths);
        }

        foreach ($source->lazy($exporter::chunkSize()) as $record) {
            $records++;

            yield array_map(
                static fn (ExportColumn $column): string => $column->toCell($record),
                $columns,
            );
        }
    }

    /**
     * The chosen columns, in the order the exporter declared them.
     *
     * Order comes from the schema rather than from the request: a file whose
     * columns move because of how a checkbox list was clicked is a file that
     * cannot be diffed against last week's.
     *
     * @param  class-string<Exporter>  $exporter
     * @param  list<string>  $columns
     * @return list<ExportColumn>
     */
    private static function selected(string $exporter, array $columns): array
    {
        $declared = $exporter::columns();

        // Two columns with one name produce two identical headings, and the
        // column picker keys its selection by name — so choosing one chooses
        // both, and unchecking it removes neither on its own.
        $names = array_map(static fn (ExportColumn $column): string => $column->getName(), $declared);
        $duplicates = array_values(array_unique(array_diff_assoc($names, array_unique($names))));

        if ($duplicates !== []) {
            throw PanelSchemaException::duplicateExportColumns($duplicates);
        }

        $selected = [];

        foreach ($declared as $column) {
            if ($columns === [] || in_array($column->getName(), $columns, true)) {
                $selected[] = $column;
            }
        }

        return $selected;
    }
}
