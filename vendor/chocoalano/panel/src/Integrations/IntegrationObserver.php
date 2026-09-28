<?php

declare(strict_types=1);

namespace PandaPanel\Integrations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PandaPanel\Jobs\SendPanelIntegration;
use Throwable;

/**
 * Hangs the six triggers off a model's own events.
 *
 * Registered once per enabled resource, at boot, against the model class the
 * resource declares. Eloquent's events are the widest seam there is: a record
 * written by a resource form, a table action, a bulk action, an importer, a
 * console command, or a queued job all pass through them, which is what makes
 * this "universal" rather than "works from the edit screen".
 *
 * What it does not catch, and cannot: `Model::query()->update()` and
 * `->delete()`, which never hydrate a model and therefore fire no events.
 * That is a property of Eloquent rather than of this class, and the panel
 * itself never writes that way.
 */
final class IntegrationObserver
{
    /**
     * Models already wired, so two panels sharing a model do not each add a
     * listener for the same event and send everything twice.
     *
     * @var array<string, true>
     */
    private static array $registered = [];

    /**
     * @param  class-string<Model>  $model
     */
    public static function register(string $model, string $panelId, string $resourceSlug, Integrations $integrations): void
    {
        $key = $model.'|'.$panelId.'|'.$resourceSlug;

        if (isset(self::$registered[$key])) {
            return;
        }

        self::$registered[$key] = true;

        foreach ($integrations->getTriggers() as $trigger) {
            // `Event::listen('eloquent.creating: App\Models\Order')` rather
            // than `Model::registerModelEvent()`, which is protected: the
            // string form is the documented way in, and it is what
            // `registerModelEvent()` itself ends up calling.
            Event::listen(
                sprintf('eloquent.%s: %s', $trigger->modelEvent(), $model),
                // The model itself, not an event-and-payload pair: Eloquent
                // dispatches `eloquent.creating: App\Models\Order` with the
                // model as the payload, and only a *wildcard* listener is
                // handed the event name alongside it.
                static function (Model $record) use ($trigger, $panelId, $resourceSlug, $integrations): void {
                    self::fire($trigger, $panelId, $resourceSlug, $record, $integrations);
                },
            );
        }
    }

    /** Only for tests, which register the same model more than once. */
    public static function forget(): void
    {
        self::$registered = [];
    }

    private static function fire(
        Trigger $trigger,
        string $panelId,
        string $resourceSlug,
        Model $record,
        Integrations $integrations,
    ): void {
        try {
            $integrations_ = PanelIntegration::query()
                ->firing($panelId, $resourceSlug, $trigger)
                ->get();
        } catch (Throwable) {
            // The table is not there yet — an application that has the package
            // but has not migrated. A missing integrations table is not a
            // reason for a record not to save.
            return;
        }

        if ($integrations_->isEmpty()) {
            return;
        }

        $payload = IntegrationDispatcher::payload($trigger, $resourceSlug, $record);

        foreach ($integrations_ as $integration) {
            // One id per integration per write, decided here so the queued
            // job's retries all reuse it.
            $deliveryId = (string) Str::uuid();

            if ($trigger->isAfter()) {
                SendPanelIntegration::dispatch(
                    $integration->id,
                    $payload,
                    $integrations->getTimeout(),
                    $deliveryId,
                );

                continue;
            }

            // Inline, because the record is about to change and this is the
            // only moment the payload is true. Its failures are swallowed by
            // the dispatcher.
            IntegrationDispatcher::send($integration, $payload, $integrations->getTimeout(), $deliveryId);
        }
    }
}
