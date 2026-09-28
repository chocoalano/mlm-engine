<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Actions\Enums\SpreadsheetFormat;

/**
 * What an export is: a set of columns, a query, and where the file lands.
 *
 * A class rather than a closure on the action, for the reason a policy is a
 * class: an export is reused by a queued job that runs in a different process
 * from the request that asked for it, and a closure cannot cross that gap.
 * The job carries this class name and nothing else.
 *
 * Everything is static. There is no state an export needs between rows that
 * is not in the query.
 */
abstract class Exporter
{
    /**
     * The columns offered, in the order they are written.
     *
     * @return list<ExportColumn>
     */
    abstract public static function columns(): array;

    /**
     * Shapes the query the export runs.
     *
     * The action hands over whatever the table was showing — the resource's
     * scope, and the current filters when the export was started from a
     * filtered list. Eager loads belong here: an export of ten thousand rows
     * with a relation column is ten thousand queries without them.
     *
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public static function query(Builder $query): Builder
    {
        return $query;
    }

    /**
     * The name, without an extension.
     *
     * Dated by default, because the second thing anyone asks about an export
     * file is when it was made.
     */
    public static function fileName(): string
    {
        return Str::kebab(class_basename(static::class)).'-'.date('Y-m-d-His');
    }

    /**
     * Where the file is written.
     *
     * `local` rather than `public` on purpose: an export is a copy of records
     * somebody was allowed to see, and a public disk would put it at a URL
     * anybody can guess. The download goes through the panel, which asks the
     * question again.
     */
    public static function disk(): string
    {
        return 'local';
    }

    /**
     * The directory on that disk.
     */
    public static function directory(): string
    {
        return 'panel-exports';
    }

    /**
     * Which formats this export offers.
     *
     * @return list<SpreadsheetFormat>
     */
    public static function formats(): array
    {
        return [SpreadsheetFormat::Csv, SpreadsheetFormat::Xlsx];
    }

    /**
     * How many records are held in memory at once.
     */
    /**
     * Whether a CSV cell that a spreadsheet would run as a formula is
     * neutralised first.
     *
     * On, because the alternative is an export that executes whatever
     * somebody typed into a text field on the machine of whoever opens it —
     * see `Csv::neutralize()`.
     *
     * Turn it off for a file another *program* reads, where nothing evaluates
     * anything and the leading apostrophe would be corruption rather than a
     * fix. Never turn it off for a file a person opens.
     */
    public static function escapesFormulas(): bool
    {
        return true;
    }

    public static function chunkSize(): int
    {
        return 500;
    }

    /**
     * Above this many records the export is queued instead of run in the
     * request.
     *
     * A number rather than a flag: a small export in a background job is a
     * worse experience than the wait it avoided, and a large one in a request
     * is a timeout. Zero always queues.
     */
    public static function queueAfter(): int
    {
        return 2000;
    }

    /**
     * The message the user is told when a queued export finishes.
     */
    public static function completedMessage(int $records): string
    {
        return __('panda-panel::actions.export.completed', ['count' => $records]);
    }
}
