<?php

declare(strict_types=1);

namespace PandaPanel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Actions\Imports\Importer;
use PandaPanel\Actions\Imports\ImportRun;
use PandaPanel\Core\PanelManager;
use PandaPanel\Notifications\Notification;
use PandaPanel\Notifications\NotificationAction;
use Throwable;

/**
 * Reads an import file away from the request that uploaded it.
 *
 * The file is already on the disk by the time this runs — the upload put it
 * there, and the job carries the path rather than the contents. A queue
 * payload holding a spreadsheet would be a spreadsheet in the database.
 *
 * The user is told what happened when it finishes, with a link to the rows
 * that failed. That link is the whole reason a partial import is acceptable:
 * without it, "412 of 500 rows imported" is a problem rather than a result.
 */
final class RunPanelImport implements ShouldQueue
{
    use Queueable;

    /**
     * Once, and deliberately not more.
     *
     * An import writes rows. A run that failed halfway has already written
     * some of them, and there is no general way to know which — the importer
     * decides what a row means, and only an importer keyed on something
     * unique could be replayed safely. Retrying would turn one bad import
     * into two, and the second failure would look exactly like the first.
     *
     * A failure is therefore reported rather than retried: the user gets the
     * report of what did land, and re-uploads the rest. That is a worse
     * automatic story and a much better manual one.
     *
     * Export is the opposite case and is configured the opposite way — see
     * `RunPanelExport`.
     */
    public int $tries = 1;

    /**
     * @param  class-string<Importer>  $importer
     * @param  array<string, int>  $mapping
     */
    public function __construct(
        private readonly string $importer,
        private readonly string $path,
        private readonly array $mapping,
        private readonly int|string $owner,
        private readonly string $panelId,
    ) {}

    public function handle(PanelManager $manager): void
    {
        $panel = $manager->get($this->panelId);

        // The importer's model, its scopes, and the panel's URLs are all read
        // through the current panel; without this the job runs outside one.
        $manager->setCurrentPanel($panel);

        $importer = $this->importer;

        $result = ImportRun::run($importer, $this->path, $this->mapping, $this->owner);

        // The upload was a means, not a record. Keeping it would accumulate
        // copies of customer data nobody asked to store.
        Storage::disk($importer::disk())->delete($this->path);

        $user = Auth::getProvider()->retrieveById($this->owner);

        if ($user === null) {
            return;
        }

        $notification = Notification::make('import-finished')
            ->title($importer::completedMessage($result['imported'], $result['failed']))
            ->icon($result['failed'] === 0 ? 'check' : 'triangle-alert')
            ->persistent();

        $result['failed'] === 0
            ? $notification->success()
            : $notification->warning();

        // The report is the whole reason a partial import is acceptable, so
        // it travels with the notification rather than being something to go
        // looking for.
        if ($result['report'] !== null) {
            $notification->actions([
                NotificationAction::make('failed-rows')
                    ->label(__('panda-panel::actions.import.download_failed_rows'))
                    ->url(route($panel->routeName('import-file'), [
                        'file' => $result['report'],
                        'importer' => $importer,
                    ], absolute: false)),
            ]);
        }

        $notification->send($user);
    }

    /**
     * What happens when the read itself fails.
     *
     * Two things, and both are the difference between a failure and a
     * disappearance. Without this the uploaded file stays on the disk — a
     * copy of somebody's customer data that nothing will ever delete — and
     * the user waits for a notification that is never coming, because the
     * only code that sends one is in `handle()` past the line that threw.
     *
     * The file goes for the same reason it goes on success: the upload was a
     * means, not a record. What is kept instead is the message, which names
     * the file so the person who uploaded it knows which one to look at.
     */
    public function failed(?Throwable $exception): void
    {
        $importer = $this->importer;

        Storage::disk($importer::disk())->delete($this->path);

        $user = Auth::getProvider()->retrieveById($this->owner);

        if ($user === null) {
            return;
        }

        Notification::make('import-failed')
            ->title(__('panda-panel::actions.import.failed_title'))
            // The exception's own message rather than a generic sentence: a
            // reader that says "unsupported file format" or "column count
            // mismatch on row 12" is telling somebody how to fix their file.
            ->body($exception?->getMessage() ?? __('panda-panel::actions.import.failed_body'))
            ->danger()
            ->icon('triangle-alert')
            ->persistent()
            ->send($user);
    }
}
