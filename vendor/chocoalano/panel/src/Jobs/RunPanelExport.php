<?php

declare(strict_types=1);

namespace PandaPanel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Actions\Exports\Exporter;
use PandaPanel\Actions\Exports\ExportRun;
use PandaPanel\Core\PanelManager;
use PandaPanel\Notifications\Notification;
use PandaPanel\Notifications\NotificationAction;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;
use Throwable;

/**
 * Writes an export away from the request that asked for it.
 *
 * Everything it carries is a scalar or a plain array. That is not a style
 * choice: an Eloquent builder holds a closure and cannot be serialized, so a
 * job that took "the query" would fail the moment it was queued. It takes the
 * description of a query instead — the resource, the table state the list was
 * showing, or an explicit selection — and rebuilds it on the other side.
 *
 * Rebuilding through the same `TableQuery` the list uses is what keeps the
 * file honest: the rows in it are the rows that were on screen, filters and
 * search included, rather than everything the resource can see.
 */
final class RunPanelExport implements ShouldQueue
{
    use Queueable;

    /**
     * Three times, and safely.
     *
     * An export only reads rows and writes a file, so a run that failed
     * halfway has changed nothing anybody can see — the half-written file is
     * replaced by the next attempt. That makes the usual causes worth
     * retrying: a database connection that dropped, a disk that was briefly
     * unreachable, a worker restarted mid-run.
     *
     * Import is the opposite case and is configured the opposite way — see
     * `RunPanelImport::$tries` for why writing rows cannot be replayed.
     */
    public int $tries = 3;

    /**
     * Backing off rather than hammering: the failures worth retrying are the
     * ones that need a moment, and three attempts a second apart is three
     * attempts against the same outage.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * @param  class-string<Exporter>  $exporter
     * @param  class-string<PanelResource>  $resource
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $tableState  the query string the list was showing
     * @param  list<int|string>|null  $keys  an explicit selection, or null for the whole list
     */
    public function __construct(
        private readonly string $exporter,
        private readonly string $resource,
        private readonly array $columns,
        private readonly SpreadsheetFormat $format,
        private readonly int|string $owner,
        private readonly array $tableState,
        private readonly ?array $keys,
        private readonly string $panelId,
    ) {}

    public function handle(PanelManager $manager): void
    {
        $panel = $manager->get($this->panelId);

        // A resource's scope, its table, and its URLs are all read through
        // the current panel. Without this the job would be running outside
        // any panel and `Resource::query()` would answer for none.
        $manager->setCurrentPanel($panel);

        $exporter = $this->exporter;
        $resource = $this->resource;

        $query = $resource::query();

        if ($this->keys !== null) {
            $query->whereKey($this->keys);
        } else {
            $schema = $resource::table(TableSchema::make());

            // A request built from the stored state rather than the real one:
            // there is no request here, and the table state is exactly what a
            // query string is.
            (new TableQuery($schema, Request::create('/', 'GET', $this->tableState)))
                ->constrain($query);
        }

        $result = ExportRun::write($exporter, $query, $this->columns, $this->format, $this->owner);

        $user = Auth::getProvider()->retrieveById($this->owner);

        if ($user === null) {
            return;
        }

        // Persistent, because a file is the point: a toast that appeared
        // while the user was on another tab would leave them with a finished
        // export and no way to find it.
        Notification::make('export-ready')
            ->title($exporter::completedMessage($result['records']))
            ->success()
            ->icon('download')
            ->persistent()
            ->actions([
                NotificationAction::make('download')
                    ->label(__('panda-panel::actions.export.download'))
                    ->url(route($panel->routeName('export-file'), [
                        'file' => $result['file'],
                        'exporter' => $exporter,
                    ], absolute: false)),
            ])
            ->send($user);
    }

    /**
     * Says so, rather than leaving somebody waiting.
     *
     * An export that fails silently is worse than one that fails loudly: the
     * user asked for a file, was told it was being prepared, and is now
     * watching a bell that will never ring. Only reached after the last
     * attempt, so it means the export genuinely cannot be produced.
     */
    public function failed(?Throwable $exception): void
    {
        $user = Auth::getProvider()->retrieveById($this->owner);

        if ($user === null) {
            return;
        }

        Notification::make('export-failed')
            ->title(__('panda-panel::actions.export.failed_title'))
            ->body($exception?->getMessage() ?? __('panda-panel::actions.export.failed_body'))
            ->danger()
            ->icon('triangle-alert')
            ->persistent()
            ->send($user);
    }
}
