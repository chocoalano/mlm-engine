<?php

declare(strict_types=1);

namespace PandaPanel\Widgets\Support;

use Illuminate\Http\Request;
use PandaPanel\Forms\FormSchema;

/**
 * The values a page's widgets are filtered by.
 *
 * Two levels, and they compose: a dashboard can filter every widget on it at
 * once ("this quarter"), and a widget can carry a filter of its own that only
 * makes sense for it ("by region"). A widget reads them merged, with its own
 * winning — a widget that declares `range` means *its* range.
 *
 * The state lives in the query string, the same place table state does, and
 * for the same reasons: a filtered dashboard is a link somebody can send, and
 * the back button means what it says. Session persistence is the same
 * arrangement too — the request wins whenever a parameter is *present*, and
 * absence is the only case that falls back to what was stored.
 *
 * Everything goes back through the declaring `FormSchema`, which is the
 * whitelist: a key the schema never declared is discarded here exactly as an
 * unknown field is on a form.
 */
final readonly class WidgetFilters
{
    /**
     * @param  array<string, mixed>  $dashboard  filters declared by the page
     * @param  array<string, array<string, mixed>>  $widgets  keyed by widget id
     */
    private function __construct(
        private array $dashboard,
        private array $widgets,
    ) {}

    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * Reads the state out of the request, narrowed by the schemas that
     * declared it.
     *
     * @param  array<string, FormSchema>  $widgetSchemas  keyed by widget id
     */
    public static function fromRequest(
        Request $request,
        ?FormSchema $dashboardSchema = null,
        array $widgetSchemas = [],
        ?string $sessionKey = null,
    ): self {
        $dashboard = $dashboardSchema === null
            ? []
            : self::resolve(
                $request,
                $dashboardSchema,
                (array) $request->query('filters', []),
                $request->query->has('filters'),
                $sessionKey === null ? null : $sessionKey.'.filters',
            );

        $submitted = (array) $request->query('widgets', []);
        $widgets = [];

        foreach ($widgetSchemas as $id => $schema) {
            $widgets[$id] = self::resolve(
                $request,
                $schema,
                is_array($submitted[$id] ?? null) ? $submitted[$id] : [],
                isset($submitted[$id]),
                $sessionKey === null ? null : $sessionKey.'.widgets.'.$id,
            );
        }

        return new self($dashboard, $widgets);
    }

    /**
     * What one widget is filtered by: the page's filters with its own on top.
     *
     * @return array<string, mixed>
     */
    public function for(string $widgetId): array
    {
        return [...$this->dashboard, ...($this->widgets[$widgetId] ?? [])];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        return $this->dashboard;
    }

    /**
     * One schema's values, defaulted, narrowed, and persisted.
     *
     * @param  array<array-key, mixed>  $submitted
     * @return array<string, mixed>
     */
    private static function resolve(
        Request $request,
        FormSchema $schema,
        array $submitted,
        bool $present,
        ?string $sessionKey,
    ): array {
        // Absence is the only case that falls back. A filter cleared to
        // nothing is a decision, and restoring what was stored over it would
        // ignore what the user just did.
        if (! $present && $sessionKey !== null && $request->hasSession()) {
            $stored = $request->session()->get($sessionKey);

            if (is_array($stored)) {
                $submitted = $stored;
            }
        }

        $state = [];

        foreach ($schema->fields() as $field) {
            $name = $field->getName();

            if (array_key_exists($name, $submitted)) {
                $state[$name] = $submitted[$name];

                continue;
            }

            // Present-but-missing means cleared; wholly absent means nothing
            // has been said yet, and the field's default is what it means
            // when nothing has.
            $state[$name] = $present ? null : $field->formValue(null);
        }

        if ($sessionKey !== null && $request->hasSession()) {
            $request->session()->put($sessionKey, $state);
        }

        return $state;
    }
}
