<?php

declare(strict_types=1);

namespace PandaPanel\Resources;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use PandaPanel\Clusters\Cluster;
use PandaPanel\Contracts\PanelContract;
use PandaPanel\Contracts\ResourceContract;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Enums\SubNavigationPosition;
use PandaPanel\Exceptions\PanelAuthorizationException;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Integrations\Integrations;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\Label;
use PandaPanel\Support\NavigationItem;
use PandaPanel\Support\ParentRecord;
use PandaPanel\Support\PolicyGate;
use PandaPanel\Tables\TableSchema;
use PandaPanel\Tenancy\Tenancy;
use PandaPanel\Widgets\Widget;

/**
 * Base class for a panel resource.
 *
 * `query()` is the single entry point for every record this resource can
 * reach: list, view, edit, delete, bulk, and action lookups all resolve
 * through it. Overriding it once is what lets a tenant, module, or
 * permission scope apply everywhere instead of being repeated per page.
 *
 * Authorization delegates to the Gate. These helpers exist for convenience
 * and for navigation visibility; they are not the security boundary. Routes
 * and actions authorize independently.
 */
abstract class Resource implements ResourceContract
{
    /** @var class-string<Model> */
    protected static string $model;

    protected static ?string $slug = null;

    protected static ?string $label = null;

    protected static ?string $pluralLabel = null;

    protected static ?string $recordTitleAttribute = null;

    protected static ?string $navigationLabel = null;

    protected static ?string $navigationIcon = null;

    protected static string|BackedEnum|null $navigationGroup = null;

    protected static ?string $activeNavigationIcon = null;

    /**
     * The cluster this resource belongs to.
     *
     * Membership is declared by the member rather than listed on the cluster,
     * so a class carries its own place in the panel and nothing has to be
     * kept in two lists that can disagree.
     *
     * @var class-string<Cluster>|null
     */
    protected static ?string $cluster = null;

    protected static int $navigationSort = 0;

    protected static bool $shouldRegisterNavigation = true;

    /**
     * Null takes the panel's position, so a resource states one only when it
     * differs from the rest.
     */
    protected static ?SubNavigationPosition $subNavigationPosition = null;

    /**
     * Opt-in: a resource is not searched globally until it says which
     * attributes may be searched. Adding a resource to a panel must never
     * silently widen what a search can reach.
     *
     * @var list<string>
     */
    protected static array $globalSearchAttributes = [];

    /**
     * How many hits this resource may contribute, within the panel's own
     * limit.
     */
    protected static int $globalSearchLimit = 5;

    /**
     * Presentation order among resources. Ties break on slug, so the order
     * is the same on every request.
     */
    protected static int $globalSearchSort = 0;

    /**
     * A resource with exactly one record: application settings, the current
     * tenant, a singleton configuration row.
     *
     * Its pages carry no `{record}` because there is nothing to choose
     * between, and the record is resolved by the resource instead of by the
     * URL.
     */
    protected static bool $singular = false;

    /**
     * Relations eager loaded on every query, so serializing a column can
     * never trigger a lazy load per row.
     *
     * @var list<string>
     */
    protected static array $with = [];

    /**
     * Whether this resource's records are soft deleted.
     *
     * Turning it on changes three things at once, and they only make sense
     * together: a record page can reach a trashed record, the restore and
     * force-delete actions become answerable, and `TrashedFilter` has
     * something to reveal. The index still hides trashed records until the
     * filter asks.
     */
    protected static bool $softDeletes = false;

    /**
     * The resource this one is nested under.
     *
     * A nested resource has no index of its own: every one of its pages sits
     * beneath a parent record (`/admin/users/3/posts/7/edit`) and its
     * `query()` is scoped to that parent's relation. It is absent from the
     * sidebar for the same reason — there is no "all posts" to navigate to.
     *
     * @var class-string<PanelResource>|null
     */
    protected static ?string $parentResource = null;

    /**
     * The relationship leading to the tenant, for a resource in a
     * tenant-scoped panel. Null means tenancy does not apply to this
     * resource — see `tenantRelationship()`.
     */
    protected static ?string $tenantRelationship = null;

    /**
     * The relation on the parent that holds this resource's records.
     *
     * Null takes the resource's plural slug, which is the usual name. Stating
     * it is what lets `PostResource` sit under `author` rather than `posts`.
     */
    protected static ?string $parentRelationship = null;

    /**
     * Resolved integration settings, keyed by resource class.
     *
     * @var array<class-string, Integrations>
     */
    private static array $integrationSettings = [];

    /**
     * @return class-string<Model>
     */
    public static function getModel(): string
    {
        // PHP's own answer here is "Typed static property
        // PandaPanel\Resources\Resource::$model must not be accessed before
        // initialization", which names this class rather than the one that
        // forgot, and does not say what to add. It is the single most common
        // thing to leave out of a new resource.
        if (! isset(static::$model)) {
            throw PanelSchemaException::missingModel(static::class);
        }

        return static::$model;
    }

    /**
     * Every read of this resource goes through here, so a panel that
     * narrowed it narrows list, view, edit, delete, bulk, action lookup, and
     * global search alike. A record this panel may not reach is a 404, not a
     * filtered row.
     *
     * A resource overriding this should call `parent::query()`, or the
     * panel's own narrowing is silently dropped.
     *
     * @return Builder<covariant Model>
     */
    public static function query(): Builder
    {
        $query = static::isNested()
            ? static::parentRelation()->getQuery()->with(static::$with)
            : static::getModel()::query()->with(static::$with);

        $query = static::applyTenantScope($query);

        return static::configurationIn(panel())?->applyQuery($query) ?? $query;
    }

    /**
     * The relationship on this resource's model that leads to the tenant, or
     * null for a resource tenancy does not apply to.
     *
     * Naming one is the whole opt-in. A resource that names nothing is not
     * scoped, which is right for the two cases that actually occur: a
     * database-per-tenant arrangement where the connection is already the
     * boundary and there is no column to scope by, and a genuinely global
     * table — a plan, a country, a feature flag — that every tenant reads.
     *
     * `belongsTo`, `belongsToMany` and `hasOneThrough` all work; the scope is
     * built with `whereHas`, so it is the relationship's own definition that
     * decides what "belongs to this tenant" means rather than a column name
     * this framework guessed.
     */
    public static function tenantRelationship(): ?string
    {
        return static::$tenantRelationship;
    }

    /**
     * Narrows a query to the current tenant.
     *
     * Three conditions, all required, and the order they are checked in is
     * the order they fail in practice: the panel has to have tenancy, the
     * resource has to name a relationship, and a tenant has to be bound.
     *
     * The third is a *throw* rather than a skip, and that is the important
     * decision here. A resource that declared itself tenant-scoped and then
     * ran unscoped because nothing was bound would return every tenant's
     * records — the exact failure this whole mechanism exists to prevent, and
     * one that looks like a working page. `Tenancy::require()` makes it an
     * exception instead. Console and queue work that legitimately runs
     * outside a request enters a tenant with `Tenancy::for()`.
     *
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    protected static function applyTenantScope(Builder $query): Builder
    {
        $panel = panel();

        if ($panel === null || ! $panel->hasTenancy()) {
            return $query;
        }

        $relationship = static::tenantRelationship();

        if ($relationship === null) {
            return $query;
        }

        $model = $query->getModel();

        if (! method_exists($model, $relationship)) {
            throw PanelRegistrationException::unknownTenantRelationship(
                static::class,
                $model::class,
                $relationship,
            );
        }

        // A method that exists is not yet a relationship. A scope, an
        // accessor, or a plain helper passes the check above and then fails
        // inside `whereHas` with "Call to a member function getRelated() on
        // null" — an error about Eloquent's internals that names neither the
        // resource nor the property that pointed at it.
        if (! $model->{$relationship}() instanceof Relation) {
            throw PanelRegistrationException::tenantRelationshipIsNotARelation(
                static::class,
                $model::class,
                $relationship,
            );
        }

        $tenant = Tenancy::require();

        return $query->whereHas(
            $relationship,
            static fn (Builder $related): Builder => $related->whereKey($tenant->getKey()),
        );
    }

    public static function isNested(): bool
    {
        return static::$parentResource !== null;
    }

    /**
     * @return class-string<PanelResource>|null
     */
    public static function parentResource(): ?string
    {
        return static::$parentResource;
    }

    /**
     * The relation on the parent record this resource's records live in.
     */
    public static function parentRelationship(): string
    {
        return static::$parentRelationship ?? Str::camel(static::defaultSlug());
    }

    /**
     * The parent's relation, which is the whole scope of a nested resource.
     *
     * Reading the parent here and nowhere else is what makes the scope
     * impossible to forget: every page, action, and lookup already goes
     * through `query()`, so a record under another parent is a 404 without
     * any page having to check.
     *
     * @return Relation<Model, Model, mixed>
     */
    protected static function parentRelation(): Relation
    {
        $parent = ParentRecord::require(static::class);
        $name = static::parentRelationship();

        if (! method_exists($parent, $name)) {
            throw PanelRegistrationException::unknownRelation($parent::class, $name, static::class);
        }

        $relation = $parent->{$name}();

        if (! $relation instanceof Relation) {
            throw PanelRegistrationException::unknownRelation($parent::class, $name, static::class);
        }

        return $relation;
    }

    abstract public static function table(TableSchema $table): TableSchema;

    /**
     * Abstract rather than defaulting to an empty schema: a resource with no
     * form should say so explicitly, not inherit a create page that silently
     * saves nothing.
     */
    abstract public static function form(FormSchema $schema): FormSchema;

    /**
     * The record's read-only presentation.
     *
     * Empty by default, and the view page falls back to deriving entries
     * from the form when a resource declares none — so adding an infolist is
     * an improvement a resource opts into rather than a migration it is
     * forced through.
     */
    public static function infolist(InfolistSchema $schema): InfolistSchema
    {
        return $schema;
    }

    /**
     * Outbound HTTP on this resource's writes.
     *
     * Off unless a resource says otherwise, because turning it on gives
     * whoever can reach the screen the ability to make the server issue
     * requests — see `Integrations` and `OutboundUrl`:
     *
     * ```php
     * public static function integrations(Integrations $integrations): Integrations
     * {
     *     return $integrations->isEnabled(true);
     * }
     * ```
     */
    public static function integrations(Integrations $integrations): Integrations
    {
        return $integrations;
    }

    /**
     * Widgets this resource exposes by default.
     *
     * The standard list page places these above the table. Other pages can
     * opt into the same list, or into a different one, through
     * `getHeaderWidgets()` and `getFooterWidgets()`.
     *
     * @return list<class-string<Widget>>
     */
    public static function getWidgets(): array
    {
        return [];
    }

    /**
     * Widgets shown above one of this resource's pages.
     *
     * `$page` is the resource page key: `index`, `create`, `view`, `edit`, or
     * `relation:{key}` for a dedicated relation page. The index page receives
     * `getWidgets()` by default because that is where aggregate widgets most
     * often belong.
     *
     * @return list<class-string<Widget>>
     */
    public static function getHeaderWidgets(string $page): array
    {
        return $page === 'index' ? static::getWidgets() : [];
    }

    /**
     * Widgets shown below one of this resource's pages.
     *
     * @return list<class-string<Widget>>
     */
    public static function getFooterWidgets(string $page): array
    {
        return [];
    }

    /**
     * The resolved settings, built once per class per request.
     *
     * Cached because the route registrar, the boot-time observer registration
     * and the page all ask the same question, and `integrations()` is a
     * method an application wrote — it should be called predictably rather
     * than once per lookup.
     */
    public static function integrationSettings(): Integrations
    {
        return self::$integrationSettings[static::class]
            ??= static::integrations(Integrations::make());
    }

    /**
     * Page key to page class. Keys become route names:
     * `panel.{panel}.resources.{slug}.{key}`.
     *
     * @return array<string, class-string>
     */
    abstract public static function pages(): array;

    /**
     * The relation managers this resource's record pages carry.
     *
     * Empty by default. A resource grows related-record tables by naming the
     * managers here, and nowhere else: the record pages, the relation
     * endpoints, and any `ManageRelatedRecords` page all resolve through this
     * one list, so a manager that is not named here cannot be addressed by a
     * request that names it.
     *
     * @return list<class-string<RelationManager>>
     */
    public static function relationManagers(): array
    {
        return [];
    }

    /**
     * The manager registered under `$key`, or null.
     *
     * @return class-string<RelationManager>|null
     *
     * @throws PanelRegistrationException
     */
    public static function relationManager(string $key): ?string
    {
        $found = null;

        foreach (static::relationManagers() as $manager) {
            if ($manager::key() !== $key) {
                continue;
            }

            // Two managers on one key would make the endpoint's answer depend
            // on declaration order, which is exactly the kind of ambiguity
            // this framework fails loudly on.
            if ($found !== null) {
                throw PanelRegistrationException::duplicateRelationKey($key, static::class);
            }

            $found = $manager;
        }

        return $found;
    }

    /**
     * The class's own slug, before any panel configures it.
     *
     * The registry needs this to work out an effective slug, so it must not
     * ask the registry back.
     */
    public static function defaultSlug(): string
    {
        return static::$slug ?? Str::of(class_basename(static::getModel()))->plural()->kebab()->toString();
    }

    public static function defaultLabel(): string
    {
        $model = class_basename(static::getModel());

        return static::$label ?? Label::resolve(
            'resources',
            $model,
            static fn (): string => Str::headline($model),
        );
    }

    /**
     * The plural, which is a word rather than an inflection of the singular.
     *
     * `Str::plural()` knows English. Applied to a translated singular it
     * produces "Penggunas" — so a resource whose singular came from the
     * application's file and whose plural did not keeps the singular
     * unchanged, which is right for every language that does not inflect and
     * no worse than nothing for the ones that do. Say the plural in
     * `resources_plural` to get a different word.
     */
    public static function defaultPluralLabel(): string
    {
        if (static::$pluralLabel !== null) {
            return static::$pluralLabel;
        }

        $model = class_basename(static::getModel());
        $translated = Label::lookup('resources_plural', $model);

        if ($translated !== null) {
            return $translated;
        }

        return Label::lookup('resources', $model) ?? Str::plural(static::defaultLabel());
    }

    /**
     * The slug this resource has in the current panel.
     *
     * A class registered in two panels may be `users` in one and `people` in
     * another, so the panel — not the class — is the authority. Outside a
     * panel there is nobody to ask, and the class's own slug is the answer.
     */
    public static function slug(): string
    {
        return static::slugIn(panel());
    }

    public static function slugIn(?Panel $panel): string
    {
        return static::configurationIn($panel)?->getSlug() ?? static::defaultSlug();
    }

    public static function label(): string
    {
        return static::configurationIn(panel())?->getLabel() ?? static::defaultLabel();
    }

    public static function pluralLabel(): string
    {
        return static::configurationIn(panel())?->getPluralLabel() ?? static::defaultPluralLabel();
    }

    /**
     * How this panel configured this resource, if it did.
     */
    public static function configurationIn(?Panel $panel): ?ResourceConfiguration
    {
        if ($panel === null) {
            return null;
        }

        return app(PanelManager::class)->resources($panel)->configurationFor(static::class);
    }

    /**
     * Used by breadcrumbs and by any UI that names a single record.
     */
    public static function recordTitle(Model $record): string
    {
        $attribute = static::$recordTitleAttribute ?? 'name';

        $value = $record->getAttribute($attribute);

        return is_scalar($value) ? (string) $value : (string) $record->getKey();
    }

    /**
     * Resolves a record through `query()`, so a record excluded by the
     * resource scope is a 404 rather than a leak.
     *
     * A resource that soft deletes resolves trashed records too. Without
     * that, a deleted record could never be opened, and so could never be
     * restored: the only route to it is the one the default scope hides. The
     * lifting is narrow — `SoftDeletingScope` and nothing else — so tenant,
     * module, and permission scopes still apply exactly as they do to a live
     * record.
     */
    public static function resolveRecord(int|string $key): Model
    {
        return static::recordQuery()->findOrFail($key);
    }

    /**
     * The same lookup, nullable, for callers that decide for themselves what
     * a missing record means — the action endpoint's 404 and a bulk
     * operation's count check want different things.
     */
    public static function findRecord(int|string $key): ?Model
    {
        return static::recordQuery()->find($key);
    }

    /**
     * @param  list<int|string>  $keys
     * @return Collection<int, Model>
     */
    public static function findRecords(array $keys): Collection
    {
        /** @var Collection<int, Model> $records */
        $records = static::recordQuery()->whereKey($keys)->get();

        return $records;
    }

    /**
     * `query()` narrowed to a single record's lookup.
     *
     * The list and the lookup differ in exactly one way: an index hides
     * trashed records until the filter asks for them, while a record page has
     * been asked for one by key and should answer about it.
     *
     * @return Builder<covariant Model>
     */
    protected static function recordQuery(): Builder
    {
        $query = static::query();

        if (static::usesSoftDeletes()) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query;
    }

    /**
     * Whether this resource's records can be trashed and brought back.
     *
     * Declared *and* corroborated: a resource says it soft deletes, and the
     * model has to actually use the trait. Detecting it from the model alone
     * would silently grow restore actions on a model that uses `SoftDeletes`
     * for something the panel was never meant to expose.
     */
    public static function usesSoftDeletes(): bool
    {
        if (! static::$softDeletes) {
            return false;
        }

        return in_array(SoftDeletes::class, class_uses_recursive(static::getModel()), true);
    }

    /**
     * @return list<string>
     */
    public static function globalSearchAttributes(): array
    {
        return static::$globalSearchAttributes;
    }

    public static function isGloballySearchable(): bool
    {
        return static::globalSearchAttributes() !== [];
    }

    public static function globalSearchLimit(): int
    {
        return static::$globalSearchLimit;
    }

    public static function globalSearchSort(): int
    {
        return static::$globalSearchSort;
    }

    public static function navigationIcon(): ?string
    {
        return static::$navigationIcon;
    }

    /**
     * A second icon worn only while this item is the active one.
     *
     * Falls back to the ordinary icon, so a resource opts into the pair
     * rather than having to state both.
     */
    public static function activeNavigationIcon(): ?string
    {
        return static::$activeNavigationIcon ?? static::$navigationIcon;
    }

    /**
     * @return class-string<Cluster>|null
     */
    public static function cluster(): ?string
    {
        return static::$cluster;
    }

    /**
     * Searching starts from `query()` like every other lookup, so a resource
     * scope narrows the search exactly as it narrows a list.
     *
     * @return Builder<covariant Model>
     */
    public static function globalSearchQuery(): Builder
    {
        return static::query();
    }

    public static function globalSearchResultTitle(Model $record): string
    {
        return static::recordTitle($record);
    }

    /**
     * Extra lines under the title. Scalars only: this is presentation, and
     * it crosses to Vue.
     *
     * @return array<string, string>
     */
    public static function globalSearchResultDetails(Model $record): array
    {
        return [];
    }

    /**
     * Where a hit leads. The view page when there is one, the edit page
     * otherwise, and the index as a last resort — each of which authorizes
     * independently when it is opened.
     */
    public static function globalSearchResultUrl(Model $record): string
    {
        $pages = static::pages();

        if (array_key_exists('view', $pages)) {
            return static::url('view', $record);
        }

        return array_key_exists('edit', $pages)
            ? static::url('edit', $record)
            : static::url();
    }

    public static function isSingular(): bool
    {
        return static::$singular;
    }

    /**
     * The one record a singular resource works on.
     *
     * Through `query()` like every other lookup, so the resource scope still
     * applies. A resource that should create the row on first visit
     * overrides this.
     */
    public static function resolveSingularRecord(): Model
    {
        return static::query()->firstOrFail();
    }

    /**
     * Where this resource's record sub-navigation sits, or null to take the
     * panel's.
     */
    public static function subNavigationPosition(): ?SubNavigationPosition
    {
        return static::$subNavigationPosition;
    }

    public static function canViewAny(): bool
    {
        return static::authorize('viewAny', static::getModel());
    }

    public static function canView(Model $record): bool
    {
        return static::authorize('view', $record);
    }

    public static function canCreate(): bool
    {
        return static::authorize('create', static::getModel());
    }

    public static function canEdit(Model $record): bool
    {
        return static::authorize('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::authorize('delete', $record);
    }

    public static function canDeleteAny(): bool
    {
        return static::authorize('deleteAny', static::getModel());
    }

    public static function canRestore(Model $record): bool
    {
        return static::authorize('restore', $record);
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::authorize('forceDelete', $record);
    }

    /**
     * The collective abilities a bulk action asks before it has a record to
     * ask about — the same shape `canDeleteAny()` answers for deletion. Each
     * record is still authorized individually before any is written.
     */
    public static function canRestoreAny(): bool
    {
        return static::authorize('restoreAny', static::getModel());
    }

    public static function canForceDeleteAny(): bool
    {
        return static::authorize('forceDeleteAny', static::getModel());
    }

    /**
     * Every ability goes through here, so strict mode is one check rather
     * than eight, and a resource overriding a `can*` method keeps the same
     * behaviour by calling this.
     *
     * The strict check itself lives in `PolicyGate` because relation
     * managers ask abilities a resource has no method for, and two copies of
     * that rule would be two places to keep it true.
     *
     * @param  Model|class-string  $argument
     *
     * @throws PanelAuthorizationException
     */
    protected static function authorize(string $ability, Model|string $argument): bool
    {
        return PolicyGate::allows($ability, $argument);
    }

    /**
     * The sidebar entry, as this panel configured it.
     *
     * Every field falls back to the class's own, so a panel states only what
     * it wants to differ.
     */
    public static function navigationItem(PanelContract $panel): ?NavigationItem
    {
        $resolved = $panel instanceof Panel ? $panel : null;
        $configuration = static::configurationIn($resolved);

        // A nested resource has no index to link to: its pages only exist
        // beneath a parent record, and the sidebar has no parent in hand.
        if (static::isNested()) {
            return null;
        }

        // Neither has a resource that registers no index page at all — one
        // reached only through another resource's relation, say. Building an
        // item for it would produce a link to a route that was never
        // registered, which fails while rendering the sidebar and so takes
        // down every page in the panel rather than just that one.
        if (! array_key_exists('index', static::pages())) {
            return null;
        }

        if (! ($configuration?->shouldRegisterNavigation() ?? static::$shouldRegisterNavigation)) {
            return null;
        }

        return NavigationItem::make(
            label: $configuration?->getNavigationLabel()
                ?? static::$navigationLabel
                ?? ($configuration?->getPluralLabel() ?? static::defaultPluralLabel()),
            href: static::url(panel: $resolved),
            icon: $configuration?->getNavigationIcon() ?? static::$navigationIcon,
            activeIcon: static::activeNavigationIcon(),
            sort: $configuration?->getNavigationSort() ?? static::$navigationSort,
            group: $configuration?->getNavigationGroup() ?? static::$navigationGroup,
        );
    }

    public static function routeName(string $page = 'index', Panel|string|null $panel = null): string
    {
        $resolved = static::resolvePanel($panel);

        return $resolved->routeName(
            sprintf('resources.%s.%s', static::slugIn($resolved), $page),
        );
    }

    /**
     * Always route-name based. Building `/admin/users` by hand would break
     * the moment a panel changes its path, and would silently bypass the
     * registration check below.
     */
    public static function url(
        string $page = 'index',
        Model|int|string|null $record = null,
        Panel|string|null $panel = null,
        Model|int|string|null $parent = null,
    ): string {
        $resolved = static::resolvePanel($panel);

        static::assertRegisteredIn($resolved);

        $parameters = [];

        // A nested resource's every route carries its parent, so a URL built
        // without one would silently drop the scope it exists for. The
        // request's own parent is the default, which is what makes links
        // between a nested resource's pages need no extra argument.
        if (static::isNested()) {
            $parentRecord = $parent ?? ParentRecord::require(static::class);

            $parameters[ParentRecord::routeParameter()] = $parentRecord instanceof Model
                ? $parentRecord->getKey()
                : $parentRecord;
        }

        if ($record !== null && ! static::isSingular()) {
            $parameters['record'] = $record instanceof Model ? $record->getKey() : $record;
        }

        return route(static::routeName($page, $resolved), $parameters, absolute: false);
    }

    /**
     * Falls back to the current panel, and refuses to guess when there is
     * none. Silently picking a panel would make cross-panel links look
     * correct while pointing somewhere else.
     */
    protected static function resolvePanel(Panel|string|null $panel): Panel
    {
        if ($panel instanceof Panel) {
            return $panel;
        }

        $manager = app(PanelManager::class);

        if (is_string($panel)) {
            return $manager->get($panel);
        }

        return $manager->currentPanel() ?? throw PanelRegistrationException::noCurrentPanel();
    }

    /**
     * Panel isolation is only real if asking for a URL in the wrong panel
     * fails loudly.
     */
    protected static function assertRegisteredIn(Panel $panel): void
    {
        if (! app(PanelManager::class)->resources($panel)->contains(static::class)) {
            throw PanelRegistrationException::resourceNotInPanel(static::class, $panel->getId());
        }
    }
}
