<?php

declare(strict_types=1);

namespace PandaPanel\Integrations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one configured request.
 *
 * Nothing here can stop a write. A `before` trigger fires just before the
 * record is saved and a `after` one just after, and in both cases the
 * response is recorded and then dropped: an integration is a notification,
 * not a gate. That is the difference between an endpoint going down and an
 * endpoint going down *and nobody being able to save anything* — and the
 * second is not a failure mode worth having for the sake of the first.
 *
 * Every send is checked against `OutboundUrl` again here, not only when the
 * integration was saved. A hostname approved last week can resolve somewhere
 * else today, and the check that matters is the one nearest the socket.
 */
final class IntegrationDispatcher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function send(
        PanelIntegration $integration,
        array $payload,
        int $timeout,
        ?string $deliveryId = null,
    ): void {
        // Stable across the retries of one delivery, so a receiver can make
        // its own handling idempotent — which matters because `after`
        // triggers are queued and retried.
        $deliveryId ??= (string) Str::uuid();

        $rejection = OutboundUrl::rejection($integration->url);

        if ($rejection !== null) {
            self::record($integration, $deliveryId, null, $rejection, null, null, 0);

            return;
        }

        $body = $integration->body_type === 'none'
            ? []
            : self::body($integration, $payload);

        // The exact bytes that are signed and sent. Signing a re-encoding of
        // the body would produce a signature the receiver cannot reproduce
        // from what arrived.
        $encoded = $integration->body_type === 'json'
            ? (string) json_encode($body)
            : http_build_query($body);

        $startedAt = microtime(true);

        try {
            $request = Http::timeout($timeout)
                ->withoutRedirecting()
                ->withHeaders([
                    ...self::headers($integration),
                    ...self::signature($integration, $encoded, $deliveryId),
                ])
                ->withQueryParameters($integration->query ?? []);

            $response = match ($integration->body_type) {
                'none' => $request->send($integration->method, $integration->url),
                'form' => $request->asForm()->send($integration->method, $integration->url, [
                    'form_params' => $body,
                ]),
                default => $request->withBody($encoded, 'application/json')
                    ->send($integration->method, $integration->url),
            };

            self::record(
                $integration,
                $deliveryId,
                $response->status(),
                $response->successful() ? null : mb_substr($response->body(), 0, 500),
                $integration->body_type === 'none' ? null : $encoded,
                $response->body(),
                self::elapsed($startedAt),
            );
        } catch (Throwable $exception) {
            // Caught rather than allowed to bubble: this runs inside the
            // request that is saving a record, and a DNS failure is not a
            // reason for that record not to exist.
            self::record(
                $integration,
                $deliveryId,
                null,
                $exception->getMessage(),
                $integration->body_type === 'none' ? null : $encoded,
                null,
                self::elapsed($startedAt),
            );

            Log::warning('Panel integration failed.', [
                'integration' => $integration->id,
                'resource' => $integration->resource,
                'trigger' => $integration->trigger->value,
                'delivery' => $deliveryId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The two headers that let a receiver trust what it was sent.
     *
     * Built from the encoded body rather than the array, because the receiver
     * only ever sees bytes — see `IntegrationSignature`.
     *
     * @return array<string, string>
     */
    private static function signature(
        PanelIntegration $integration,
        string $encoded,
        string $deliveryId,
    ): array {
        $headers = [IntegrationSignature::DELIVERY_HEADER => $deliveryId];

        $secret = $integration->secret;

        // Null only for a row written before signing existed. Everything
        // created since carries one, generated on the model.
        if (is_string($secret) && $secret !== '') {
            $headers[IntegrationSignature::SIGNATURE_HEADER] = IntegrationSignature::header(
                $secret,
                Date::now()->getTimestamp(),
                $encoded,
            );
        }

        return $headers;
    }

    private static function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * The body, with the payload substituted in.
     *
     * A blank body means "send the payload as it is", which is what almost
     * every integration wants. A body that was written by hand is passed
     * through `{{ }}` substitution so a receiving system that needs its own
     * shape can have it without the panel knowing anything about that shape.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function body(PanelIntegration $integration, array $payload): array
    {
        $body = trim((string) $integration->body);

        if ($body === '') {
            return $payload;
        }

        $decoded = json_decode(IntegrationTemplate::render($body, $payload), true);

        return is_array($decoded) ? $decoded : $payload;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(PanelIntegration $integration): array
    {
        $headers = [];

        foreach ($integration->headers ?? [] as $name => $value) {
            if ($name !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * What the receiving system is told.
     *
     * The record's attributes rather than the model: an integration is
     * described by data that crossed the wire, and a serialized model would
     * carry loaded relations, appended accessors, and whatever else happened
     * to be hydrated at the moment it fired.
     *
     * Hidden attributes are left out, so a `password` or an API token on the
     * model does not leave the building because somebody added a webhook.
     *
     * @return array<string, mixed>
     */
    public static function payload(Trigger $trigger, string $resource, Model $record): array
    {
        return [
            'trigger' => $trigger->value,
            'resource' => $resource,
            'occurred_at' => Date::now()->toIso8601String(),
            'record' => $record->attributesToArray(),
            // Only on an update, and only what actually moved — the single
            // most asked-for thing a receiving system does not otherwise have.
            'changed' => $trigger === Trigger::BeforeUpdate || $trigger === Trigger::AfterUpdate
                ? array_keys($record->getDirty())
                : [],
        ];
    }

    /**
     * The summary on the integration, and the row in its history.
     *
     * The summary stays: it is what the list on the screen colours itself
     * with, and reading one column beats aggregating a child table on every
     * render. The history row is what explains it.
     */
    private static function record(
        PanelIntegration $integration,
        string $deliveryId,
        ?int $status,
        ?string $error,
        ?string $requestBody,
        ?string $responseBody,
        int $durationMs,
    ): void {
        $attemptedAt = Date::now();

        $integration->forceFill([
            'last_status' => $status,
            'last_error' => $error,
            'last_attempted_at' => $attemptedAt,
        ])->saveQuietly();

        if (! PanelIntegrationDelivery::enabled()) {
            return;
        }

        $limit = PanelIntegrationDelivery::BODY_LIMIT;

        PanelIntegrationDelivery::query()->create([
            'integration_id' => $integration->id,
            // Copied rather than joined: an integration's URL and trigger can
            // be edited, and a history reporting today's URL for last week's
            // delivery would be worse than none.
            'trigger' => $integration->trigger->value,
            'method' => $integration->method,
            'url' => $integration->url,
            'delivery_id' => $deliveryId,
            'status' => $status,
            'duration_ms' => $durationMs,
            'error' => $error === null ? null : mb_substr($error, 0, $limit),
            'request_body' => $requestBody === null ? null : mb_substr($requestBody, 0, $limit),
            'response_body' => $responseBody === null ? null : mb_substr($responseBody, 0, $limit),
            'attempted_at' => $attemptedAt,
        ]);

        // Immediately, because this is the one moment the table is known to
        // have grown. Nothing has to be scheduled for the bound to hold.
        PanelIntegrationDelivery::prune($integration->id);
    }
}
