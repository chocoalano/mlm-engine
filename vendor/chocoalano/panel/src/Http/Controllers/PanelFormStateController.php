<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PandaPanel\Core\PanelManager;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Support\FormContext;
use PandaPanel\Forms\Support\FormState;

/**
 * Rebuilds a form after a `live()` field changed.
 *
 * For a dependency the declarative conditions cannot express — a select whose
 * options come from another field's value, a total computed from three of
 * them. The browser sends what has been typed; the server rebuilds the schema
 * against it and answers with the new one.
 *
 * This is not a submit. Nothing is validated and nothing is written: it
 * answers what the form should *look* like now, and what — if anything — the
 * server has decided it should now *hold*. That separation is what makes it
 * safe to call on every keystroke of a live field.
 *
 * ## Every surface, not just create and edit
 *
 * `live()` used to work on the resource's own forms and nowhere else, because
 * this endpoint could only build a resource's schema. An action's form and a
 * relation's form went through the same renderer, declared the same `live()`,
 * and quietly did nothing — so the dependency had to be worked around once per
 * surface. The context now says which form it is (see `FormContext`), and the
 * five surfaces are five branches of one resolver rather than five endpoints.
 *
 * ## State patches
 *
 * The response carries two things. `form` is what the form should look like;
 * `statePatch` is what the server has decided it should hold, and only what a
 * callback explicitly changed. The renderer preserves what the user typed
 * when a schema is rebuilt — which is right, and which used to leave a server
 * that wanted to clear a now-invalid child field with no way to say so. A
 * patch is the server saying it.
 *
 * Nothing in the request can name a patch. They are produced only by
 * `afterStateUpdated` callbacks the schema declared, running on the server —
 * see `FormState`.
 *
 * JSON rather than Inertia, for the reason the other form endpoints are: a
 * full page response would discard what the user is in the middle of typing.
 */
final class PanelFormStateController
{
    public function __construct(private readonly PanelManager $manager) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Resolves and authorizes together. A refresh must not be a side door
        // into a form the user could not have opened: whatever ability the
        // form itself needs is asked again here, on every keystroke.
        $context = FormContext::resolve($this->manager, $request);

        $schema = $context->schema();

        abort_if($schema === null, 400, __('panda-panel::errors.action_no_form'));

        $record = $context->stateRecord();

        $state = new FormState($this->submitted($request, $schema));

        $this->runUpdateHook($request, $schema, $state, $record);

        return response()->json([
            // Serialized against the state *after* the hook ran, so a field
            // the callback cleared is rebuilt as cleared rather than being
            // rebuilt as it was and then patched over.
            'form' => $schema->toArrayWithState($record, $state->all()),
            // An object rather than a list, so an empty one crosses the wire
            // as `{}` and the client's "is there anything to apply" check is
            // the same shape either way.
            'statePatch' => (object) $state->patches(),
        ]);
    }

    /**
     * The submitted values, narrowed to the fields the schema declares.
     *
     * A key that is not a field is discarded here, so nothing downstream has
     * to wonder whether the state it was given is the schema's. That includes
     * anything shaped like a patch: a patch is not something a request can
     * send, and a key called `statePatch` is simply not a field.
     *
     * @return array<string, mixed>
     */
    private function submitted(Request $request, FormSchema $schema): array
    {
        $submitted = $request->input('state');
        $submitted = is_array($submitted) ? $submitted : [];

        $state = [];

        foreach ($schema->fields() as $field) {
            $name = $field->getName();

            if (array_key_exists($name, $submitted)) {
                $state[$name] = $submitted[$name];
            }
        }

        return $state;
    }

    /**
     * Runs `afterStateUpdated` for the field that changed, and only if it is
     * one that asked to be live.
     *
     * The callback is handed the state object, so it can read its siblings
     * and — through `Set` — say that one of them is no longer valid. A
     * callback written before that existed is called exactly as it always
     * was; see `CallbackParameters` for the rule.
     */
    private function runUpdateHook(
        Request $request,
        FormSchema $schema,
        FormState $state,
        ?Model $record,
    ): void {
        $changed = $request->input('changed');

        if (! is_string($changed)) {
            return;
        }

        $field = $schema->field($changed, $record);

        // A field that did not declare itself live has no hook to run here,
        // whatever the request says changed.
        if ($field === null || ! $field->isLive()) {
            return;
        }

        $field->handleStateUpdated(
            $state->get($changed),
            $request->input('previous'),
            $record,
            $state,
        );
    }
}
