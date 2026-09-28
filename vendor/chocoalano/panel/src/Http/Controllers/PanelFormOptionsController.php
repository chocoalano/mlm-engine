<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PandaPanel\Core\PanelManager;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Support\FormContext;
use PandaPanel\Forms\Support\FormState;
use PandaPanel\Forms\Support\FormSurface;
use PandaPanel\Resources\RelationForm;

/**
 * Answers what a searchable select may offer.
 *
 * A relation-backed select renders one bounded page of a table that may have
 * thousands of rows. Without this the other rows are simply unreachable — the
 * field would validate a key it had no way to show. The search runs on the
 * server for the same reason the option list is built there: the query, the
 * related model, and the relation name never reach the browser.
 *
 * The field is resolved out of the schema that declared it, so a request can
 * only search a field that exists on a form the user can already open. It
 * never names a column, a table, or a model — only a field name the schema
 * either has or does not.
 *
 * ## Why the form's values are sent too
 *
 * A dependent select is one whose choices are a function of a sibling: the
 * employees in the chosen department, the shifts a chosen site runs. The
 * search used to know the term and the field and nothing else, so such a
 * select could only search the whole table and then refuse most of what it
 * found — the dependency had to be re-implemented in every application that
 * wanted one.
 *
 * The values travel in the body, on a POST, because there is no bound on how
 * much a form holds and a query string has one. They are narrowed to the
 * fields this schema declares before any callback sees them, exactly as they
 * are on submit — so a key that was never a field cannot reach a query
 * through here either. GET still works and simply carries no state, which is
 * what every select that depends on nothing does.
 *
 * JSON rather than Inertia, like the search endpoint: re-rendering the page
 * somebody is filling in to answer a keystroke would throw away what they
 * typed.
 */
final class PanelFormOptionsController
{
    /** A bound the request cannot raise. */
    private const MAX_RESULTS = 50;

    public function __construct(private readonly PanelManager $manager) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Resolves which form this is and proves the user may open it, in one
        // decision. Which schema gets built and which ability gets asked are
        // the same branch — see `FormContext`.
        $context = FormContext::resolve($this->manager, $request);

        $field = $request->input('field');

        abort_unless(is_string($field), 422, __('panda-panel::errors.invalid_field'));

        $search = $request->input('search');
        $search = is_string($search) ? mb_substr(trim($search), 0, 255) : null;

        // The select naming the record to join is not a schema field backed by
        // a column; its options are the relation's own answer to "what is not
        // in here yet".
        if ($context->surface === FormSurface::Relation && $field === RelationForm::RELATED_FIELD) {
            return response()->json([
                'options' => ($context->relation)::attachableOptions(
                    $context->owner ?? abort(404),
                    $search,
                    self::MAX_RESULTS,
                ),
            ]);
        }

        $schema = $context->schema();

        abort_if($schema === null, 400, __('panda-panel::errors.action_no_form'));

        $record = $context->stateRecord();
        $state = new FormState($this->submitted($request, $schema, $record));

        $component = $schema->withState($state->all())->field($field, $record);

        // A field the schema does not declare does not exist, however the
        // request spells it — the same rule that governs sorting and
        // filtering.
        abort_if($component === null, 404, __('panda-panel::errors.unknown_field'));
        abort_unless($component instanceof Select, 400, __('panda-panel::errors.field_has_no_options'));

        return response()->json([
            'options' => $component->resolveOptions($context->modelClass(), $search, $state),
        ]);
    }

    /**
     * The values the form currently holds, narrowed to its own fields.
     *
     * The same narrowing the state endpoint and the submit apply. A key that
     * is not a field is discarded here, so an option callback is never handed
     * something the schema did not declare.
     *
     * @return array<string, mixed>
     */
    private function submitted(Request $request, FormSchema $schema, ?Model $record): array
    {
        $submitted = $request->input('state');

        if (! is_array($submitted)) {
            return [];
        }

        $state = [];

        foreach ($schema->fields($record) as $field) {
            $name = $field->getName();

            if (array_key_exists($name, $submitted)) {
                $state[$name] = $submitted[$name];
            }
        }

        return $state;
    }
}
