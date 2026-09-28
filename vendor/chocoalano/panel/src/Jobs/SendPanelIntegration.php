<?php

declare(strict_types=1);

namespace PandaPanel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PandaPanel\Integrations\IntegrationDispatcher;
use PandaPanel\Integrations\PanelIntegration;

/**
 * An `after` integration, off the request's critical path.
 *
 * Only the `after` triggers are queued. A `before` one describes the record
 * as it is about to be written, and by the time a worker picked it up that
 * state would be gone — so those are sent inline, with a short timeout and
 * their failures swallowed.
 *
 * The payload travels as an array rather than as a serialized model. The
 * record may not exist any more by the time this runs — `after_delete` is
 * precisely the case — and `SerializesModels` would try to reload it and
 * throw.
 */
final class SendPanelIntegration implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly int $integrationId,
        private readonly array $payload,
        private readonly int $timeout,
        /**
         * Assigned when the write happened, not when the worker runs, so all
         * three attempts of one delivery carry the same id and a receiver can
         * deduplicate them.
         */
        private readonly ?string $deliveryId = null,
    ) {}

    public function handle(): void
    {
        $integration = PanelIntegration::query()->find($this->integrationId);

        // Deleted or turned off between the write and the worker picking this
        // up. Both mean the same thing: do not send it.
        if ($integration === null || ! $integration->is_active) {
            return;
        }

        IntegrationDispatcher::send($integration, $this->payload, $this->timeout, $this->deliveryId);
    }

    /**
     * Spread out rather than immediate: the usual reason an integration fails
     * is that the far end is briefly unavailable, and three requests in three
     * seconds is the least helpful thing to do about that.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }
}
