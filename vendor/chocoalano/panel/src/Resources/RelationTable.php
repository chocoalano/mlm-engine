<?php

declare(strict_types=1);

namespace PandaPanel\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Relations\AssociateAction;
use PandaPanel\Actions\Relations\AttachAction;
use PandaPanel\Actions\Relations\CreateRelatedAction;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\RelationEndpoints;
use PandaPanel\Tables\Group;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;

/**
 * One relation manager, serialized for the page that carries it.
 *
 * The payload is the same shape a resource index sends — definition, applied
 * state, rows, pagination — plus the endpoints and the header actions that
 * belong to a relation. Reusing the shape is what lets the frontend render a
 * relation table with the same components as a resource table instead of a
 * parallel set that would drift.
 *
 * Every table on a record page reads its state from its own namespace in the
 * query string, so sorting one leaves the others where they were.
 */
final readonly class RelationTable
{
    /** The query-string key each relation's state lives under. */
    private const STATE_ROOT = 'relations';

    /**
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<RelationManager>  $manager
     */
    public function __construct(
        private string $resource,
        private string $manager,
        private Model $owner,
    ) {}

    public static function stateKey(string $relationKey): string
    {
        return self::STATE_ROOT.'.'.$relationKey;
    }

    /**
     * Null when the user may not read this relation at all, so an
     * unauthorized manager never queries and never appears.
     *
     * @return array<string, mixed>|null
     */
    public function toArray(Request $request): ?array
    {
        $manager = $this->manager;

        if (! $manager::canViewAny($this->owner)) {
            return null;
        }

        $schema = $manager::table(TableSchema::make(), $this->owner);
        $namespace = self::STATE_ROOT.'.'.$manager::key();

        $query = new TableQuery(
            $schema,
            $request,
            $namespace,
            sessionKey: sprintf(
                'panel.%s.table.%s.%s',
                panel()?->getId() ?? 'none',
                $this->resource::slug(),
                $manager::key(),
            ),
        );
        $relation = $manager::relationForTable($this->owner);
        $records = $query->paginateRelation($relation);

        // Resolved once, for the same reason `ListRecords` does: `state()`
        // re-reads the request, and four props want the same answer.
        $state = $query->state();
        $visible = $state['columns']['visible'];

        return [
            'key' => $manager::key(),
            'title' => $manager::title(),
            'icon' => $manager::icon(),
            'stateKey' => $namespace,
            'table' => $schema->toArray(),
            'state' => $state,
            'rows' => $this->rows($schema, $records, $query->activeGroup(), $visible),
            'summaries' => $schema->hasSummaries()
                ? $schema->summaries($relation->getQuery(), array_values($records->items()), $visible)
                : [],
            'groupSummaries' => $query->activeGroup() === null
                ? []
                : $schema->groupSummaries(
                    $relation->getQuery(),
                    array_values($records->items()),
                    $query->activeGroup(),
                    $visible,
                ),
            'pagination' => [
                'page' => $records->currentPage(),
                'perPage' => $records->perPage(),
                'total' => $records->total(),
                'lastPage' => $records->lastPage(),
                'from' => (int) $records->firstItem(),
                'to' => (int) $records->lastItem(),
            ],
            'headerActions' => $this->headerActions($schema),
            'endpoints' => RelationEndpoints::forManager($this->resource, $manager, $this->owner),
        ];
    }

    /**
     * Create, attach, and associate, resolved here rather than declared per
     * manager: all three are answers to what the relation *is*, and a manager
     * that had to list them would be able to offer an attach on a `hasMany`.
     *
     * Attach and associate are mutually exclusive by construction — each is
     * hidden for the shape the other belongs to — so a relation offers one
     * way to bring in an existing record, never two.
     *
     * @return list<array<string, mixed>>
     */
    private function headerActions(TableSchema $schema): array
    {
        $actions = [
            CreateRelatedAction::make($this->resource, $this->manager, $this->owner),
            AttachAction::make($this->resource, $this->manager, $this->owner),
            AssociateAction::make($this->resource, $this->manager, $this->owner),

            // And whatever the manager declared for itself.
            //
            // These used to be built and thrown away. `RelationManager::table()`
            // returns an ordinary `TableSchema`, so `->headerActions([...])` has
            // always been callable on it — the list simply never reached this
            // method, so the actions were never serialized, never rendered and
            // never resolvable. A manager could declare one and watch nothing
            // happen, with no error anywhere to say why.
            //
            // After the three built-ins rather than before: create, attach and
            // associate are what the relation *is*, and a manager's own actions
            // read as additions to that rather than replacements for it.
            ...$schema->getHeaderActions(),
        ];

        $serialized = [];

        foreach ($actions as $action) {
            // No record: a header action is about the relation, not a row.
            $definition = $action->toArray();

            if ($definition !== null) {
                $serialized[] = $definition;
            }
        }

        return $serialized;
    }

    /**
     * @param  LengthAwarePaginator<int, covariant Model>  $records
     * @return list<array<string, mixed>>
     */
    private function rows(
        TableSchema $schema,
        LengthAwarePaginator $records,
        ?Group $group = null,
        ?array $visible = null,
    ): array {
        return array_values(array_map(
            static fn (Model $record): array => $schema->toRow($record, $group, $visible),
            $records->items(),
        ));
    }

    /**
     * Every manager a resource declares, serialized for one record.
     *
     * @param  class-string<PanelResource>  $resource
     * @return list<array<string, mixed>>
     */
    public static function forRecord(string $resource, Model $owner, Request $request): array
    {
        $tables = [];

        foreach ($resource::relationManagers() as $manager) {
            $serialized = (new self($resource, $manager, $owner))->toArray($request);

            if ($serialized !== null) {
                $tables[] = $serialized;
            }
        }

        return $tables;
    }

    /**
     * One named manager, for a page that shows a single relation.
     *
     * @param  class-string<PanelResource>  $resource
     * @param  class-string<RelationManager>  $manager
     * @return array<string, mixed>|null
     */
    public static function forManager(
        string $resource,
        string $manager,
        Model $owner,
        Request $request,
    ): ?array {
        return (new self($resource, $manager, $owner))->toArray($request);
    }

    /**
     * @param  class-string<RelationManager>  $manager
     */
    public static function actionFor(string $manager, Model $owner, string $name): ?Action
    {
        return $manager::table(TableSchema::make(), $owner)->getRecordAction($name);
    }

    /**
     * @param  class-string<RelationManager>  $manager
     */
    public static function bulkActionFor(string $manager, Model $owner, string $name): ?Action
    {
        return $manager::table(TableSchema::make(), $owner)->getBulkAction($name);
    }

    /**
     * One of the manager's own header actions.
     *
     * Only the manager's: the three built-ins are relation *operations* with
     * their own endpoint and their own form, and resolving them here would
     * give them a second way in that authorizes differently from the first.
     *
     * @param  class-string<RelationManager>  $manager
     */
    public static function headerActionFor(string $manager, Model $owner, string $name): ?Action
    {
        foreach ($manager::table(TableSchema::make(), $owner)->getHeaderActions() as $action) {
            if ($action->getName() === $name) {
                return $action;
            }
        }

        return null;
    }
}
