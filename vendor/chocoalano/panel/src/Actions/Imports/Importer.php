<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Imports;

use Illuminate\Database\Eloquent\Model;

/**
 * What an import is: a model, a set of columns, and how a row becomes a
 * record.
 *
 * A class for the same reason an exporter is one — a queued import runs in a
 * different process from the request that uploaded the file, and only a class
 * name crosses that gap.
 *
 * The default `resolve()` inserts. Override it to update an existing record
 * instead, which is the other half of what people mean by "import": a file of
 * corrections is not a file of new rows.
 */
abstract class Importer
{
    /**
     * @return class-string<Model>
     */
    abstract public static function model(): string;

    /**
     * @return list<ImportColumn>
     */
    abstract public static function columns(): array;

    /**
     * Finds the record a row belongs to, or a new one.
     *
     * Given the row already cast and with relations resolved. Returning an
     * existing record makes the import an update; returning a new one makes
     * it an insert. Returning null skips the row without counting it as a
     * failure, for a file that legitimately contains rows this import is not
     * about.
     *
     * @param  array<string, mixed>  $data
     */
    public static function resolve(array $data): ?Model
    {
        $model = static::model();

        return new $model;
    }

    /**
     * Rules applied to the whole row, on top of the columns' own.
     *
     * For the checks that are about a row rather than a cell — "one of these
     * two must be present", a uniqueness rule that spans columns.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [];
    }

    /**
     * How many rows are read and written at a time.
     */
    public static function chunkSize(): int
    {
        return 200;
    }

    /**
     * Above this many rows the import is queued rather than run in the
     * request. Zero always queues.
     */
    public static function queueAfter(): int
    {
        return 500;
    }

    /**
     * Where the uploaded file and the failure report are kept.
     */
    public static function disk(): string
    {
        return 'local';
    }

    public static function directory(): string
    {
        return 'panel-imports';
    }

    /**
     * What the user is told when it finishes.
     */
    public static function completedMessage(int $imported, int $failed): string
    {
        if ($failed === 0) {
            return __('panda-panel::actions.import.completed', ['count' => $imported]);
        }

        return __('panda-panel::actions.import.completed_with_failures', [
            'count' => $imported,
            'failed' => $failed,
        ]);
    }
}
