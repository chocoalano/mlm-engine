<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Integrations\IntegrationDispatcher;
use PandaPanel\Integrations\IntegrationSignature;
use PandaPanel\Integrations\OutboundUrl;
use PandaPanel\Integrations\PanelIntegration;
use PandaPanel\Integrations\PanelIntegrationDelivery;
use PandaPanel\Integrations\Trigger;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * The integrations screen for one resource.
 *
 * Three authorization gates, and all three are needed:
 *
 * 1. The resource must have opted in. A resource that never called
 *    `isEnabled(true)` has no such screen, and the route 404s rather than
 *    rendering an empty one.
 * 2. The user must be able to see the resource at all (`viewAny`), because
 *    integrations describe its records.
 * 3. The user must pass the `manage-panel-integrations` gate. Configuring an
 *    integration is not "editing a record" — it is telling the server to make
 *    HTTP requests, and the ability to do that deserves to be granted
 *    separately from the ability to edit a row.
 *
 * The third denies when no gate is defined, which is the safe direction: an
 * application that has not thought about who may do this has decided nobody
 * may.
 */
final class PanelIntegrationController
{
    public function index(Request $request, string $resource): Response
    {
        $class = $this->authorize($resource);

        return Inertia::render('panel/resources/Integrations', [
            'page' => [
                'title' => __('panda-panel::integrations.page.title', [
                    'resource' => $class::pluralLabel(),
                ]),
                'heading' => __('panda-panel::integrations.page.heading'),
                'subheading' => __('panda-panel::integrations.page.subheading', [
                    'label' => mb_strtolower($class::label()),
                ]),
                'breadcrumbs' => [],
                'headerActions' => [],
                'scope' => 'resource:'.$class::slug(),
                'cluster' => null,
            ],
            'resource' => [
                'slug' => $class::slug(),
                'label' => $class::label(),
                'pluralLabel' => $class::pluralLabel(),
                'indexUrl' => $class::url(),
            ],
            'triggers' => $this->triggerOptions($class),
            'integrations' => $this->integrations($class),
            'endpoints' => $this->endpoints($class),
            // Shown on the screen rather than only in the config file, so a
            // refused URL is understood before it is typed.
            'allowedHosts' => array_values((array) config('panda-panel.integrations.allowed_hosts', [])),
        ]);
    }

    public function store(Request $request, string $resource): RedirectResponse
    {
        $class = $this->authorize($resource);

        $data = $this->validated($request, $class);

        PanelIntegration::query()->create([
            ...$data,
            'panel' => $this->panelId(),
            'resource' => $class::slug(),
        ]);

        return back()->with('success', __('panda-panel::integrations.saved'));
    }

    public function update(Request $request, string $resource, string $integration): RedirectResponse
    {
        $class = $this->authorize($resource);

        $model = $this->find($class, $integration);

        $model->update($this->validated($request, $class));

        return back()->with('success', __('panda-panel::integrations.saved'));
    }

    public function destroy(Request $request, string $resource, string $integration): RedirectResponse
    {
        $class = $this->authorize($resource);

        $this->find($class, $integration)->delete();

        return back()->with('success', __('panda-panel::integrations.deleted'));
    }

    /**
     * Sends the request once, now, with a sample payload.
     *
     * The Send button. It goes through exactly the dispatcher a real trigger
     * uses — same allowlist check, same headers, same body rendering — because
     * a test that took a different path would be a test of a different thing.
     */
    public function send(Request $request, string $resource, string $integration): JsonResponse
    {
        $class = $this->authorize($resource);

        $model = $this->find($class, $integration);

        $record = $class::query()->first();

        IntegrationDispatcher::send(
            $model,
            IntegrationDispatcher::payload(
                $model->trigger,
                $class::slug(),
                $record ?? new ($class::getModel()),
            ),
            $class::integrationSettings()->getTimeout(),
        );

        $model->refresh();

        return response()->json([
            'status' => $model->last_status,
            'error' => $model->last_error,
            'attemptedAt' => $model->last_attempted_at?->toIso8601String(),
        ]);
    }

    /**
     * Replaces the signing secret.
     *
     * Every send after this signs with the new one, so the receiving system
     * has to be updated in the same breath — which is why it is a deliberate
     * button rather than something that happens on save.
     */
    public function rotate(Request $request, string $resource, string $integration): RedirectResponse
    {
        $class = $this->authorize($resource);

        $this->find($class, $integration)
            ->forceFill(['secret' => IntegrationSignature::generate()])
            ->save();

        return back()->with('success', __('panda-panel::integrations.secret_rotated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $resource): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', 'string'],
            'method' => ['required', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'url' => ['required', 'string', 'max:2048'],
            'headers' => ['array'],
            'headers.*' => ['nullable', 'string', 'max:2048'],
            'query' => ['array'],
            'query.*' => ['nullable', 'string', 'max:2048'],
            'body_type' => ['required', 'string', 'in:json,form,none'],
            'body' => ['nullable', 'string', 'max:65535'],
            'is_active' => ['boolean'],
        ]);

        $settings = $resource::integrationSettings();

        $trigger = Trigger::tryFrom($data['trigger']);

        abort_if(
            $trigger === null || ! $settings->supports($trigger),
            422,
            __('panda-panel::errors.unsupported_trigger'),
        );

        // The allowlist is enforced here as well as at send time. Storing a
        // URL that can never be reached would be a row that looks configured
        // and silently never fires — which is the failure this screen exists
        // to make visible.
        $rejection = OutboundUrl::rejection($data['url']);

        abort_if($rejection !== null, 422, $rejection ?? '');

        return $data;
    }

    /**
     * @param  class-string<PanelResource>  $resource
     */
    private function find(string $resource, string $integration): PanelIntegration
    {
        // Scoped by panel and resource, not looked up by id alone: an id from
        // another resource's screen must not be editable from this one.
        return PanelIntegration::query()
            ->where('panel', $this->panelId())
            ->where('resource', $resource::slug())
            ->findOrFail($integration);
    }

    /**
     * @return class-string<PanelResource>
     */
    private function authorize(string $resource): string
    {
        $manager = app(PanelManager::class);

        $panel = $manager->currentPanel()
            ?? throw PanelRegistrationException::noCurrentPanel();

        $class = $manager->resources($panel)->bySlug($resource);

        // The registry is typed to the contract; everything below is declared
        // on the base class, which is what a panel actually registers.
        abort_if($class === null || ! is_subclass_of($class, PanelResource::class), 404);
        abort_unless($class::integrationSettings()->enabled(), 404);
        abort_unless($class::canViewAny(), 403);
        abort_unless(Gate::allows('manage-panel-integrations', $class), 403);

        return $class;
    }

    private function panelId(): string
    {
        return app(PanelManager::class)->currentPanel()?->getId()
            ?? throw PanelRegistrationException::noCurrentPanel();
    }

    /**
     * @param  class-string<PanelResource>  $resource
     * @return list<array{value: string, label: string}>
     */
    private function triggerOptions(string $resource): array
    {
        return array_values(array_map(
            static fn (Trigger $trigger): array => [
                'value' => $trigger->value,
                'label' => $trigger->label(),
            ],
            $resource::integrationSettings()->getTriggers(),
        ));
    }

    /**
     * @param  class-string<PanelResource>  $resource
     * @return list<array<string, mixed>>
     */
    private function integrations(string $resource): array
    {
        return PanelIntegration::query()
            ->with('deliveries')
            ->where('panel', $this->panelId())
            ->where('resource', $resource::slug())
            ->orderBy('name')
            ->get()
            ->map(static fn (PanelIntegration $integration): array => [
                'id' => $integration->id,
                'name' => $integration->name,
                'trigger' => $integration->trigger->value,
                'method' => $integration->method,
                'url' => $integration->url,
                'headers' => $integration->headers ?? [],
                'query' => $integration->query ?? [],
                'bodyType' => $integration->body_type,
                'body' => $integration->body,
                'isActive' => $integration->is_active,
                'lastStatus' => $integration->last_status,
                'lastError' => $integration->last_error,
                'lastAttemptedAt' => $integration->last_attempted_at?->toIso8601String(),
                // Shown so it can be pasted into the receiving system. It is
                // encrypted at rest and only ever leaves here for somebody who
                // already passed the manage gate.
                'secret' => $integration->secret,
                'deliveries' => $integration->deliveries
                    ->map(static fn (PanelIntegrationDelivery $delivery): array => [
                        'id' => $delivery->id,
                        'deliveryId' => $delivery->delivery_id,
                        'trigger' => $delivery->trigger->value,
                        'method' => $delivery->method,
                        'url' => $delivery->url,
                        'status' => $delivery->status,
                        'durationMs' => $delivery->duration_ms,
                        'error' => $delivery->error,
                        'requestBody' => $delivery->request_body,
                        'responseBody' => $delivery->response_body,
                        'attemptedAt' => $delivery->attempted_at->toIso8601String(),
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @param  class-string<PanelResource>  $resource
     * @return array<string, string>
     */
    private function endpoints(string $resource): array
    {
        $panel = app(PanelManager::class)->currentPanel()
            ?? throw PanelRegistrationException::noCurrentPanel();

        $name = $panel->routeName('resources.'.$resource::slug().'.integrations');

        return [
            'store' => route($name.'.store', absolute: false),
            // The id is appended by the client; a placeholder keeps every URL
            // on this screen server-built.
            'update' => route($name.'.update', ['integration' => '__id__'], absolute: false),
            'rotate' => route($name.'.rotate', ['integration' => '__id__'], absolute: false),
            'destroy' => route($name.'.destroy', ['integration' => '__id__'], absolute: false),
            'send' => route($name.'.send', ['integration' => '__id__'], absolute: false),
        ];
    }
}
