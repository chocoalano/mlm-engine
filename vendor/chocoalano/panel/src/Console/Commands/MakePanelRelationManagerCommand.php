<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Support\Str;

/**
 * Generates a relation manager, and the page for it when one is asked for.
 *
 * The relation's *shape* decides which actions belong on it, and the shape is
 * not something the generator can read off a class name — a `hasMany` is
 * created and deleted, a `belongsToMany` is attached and detached. So the
 * type is an option, and picking the wrong one produces a manager offering an
 * operation the relation cannot perform rather than one silently missing it.
 */
final class MakePanelRelationManagerCommand extends PanelGeneratorCommand
{
    private const TYPES = ['has-many', 'belongs-to-many'];

    protected $signature = 'make:panel-relation-manager
        {name : The relation name on the owner model, such as posts}
        {--panel= : The panel it belongs to}
        {--resource= : The resource that owns the relation}
        {--type=has-many : has-many or belongs-to-many}
        {--soft-deletes : Offer the trashed filter and the restore actions}
        {--page : Also generate a ManageRelatedRecords page for it}
        {--force}';

    protected $description = 'Create a relation manager for a panel resource';

    public function handle(): int
    {
        $panel = $this->panelName((string) $this->option('panel'));
        $type = (string) $this->option('type');

        // The resource is named the way `make:panel-resource` names it: the
        // directory is plural, the class is singular. Taking the option as
        // either and deriving both keeps `--resource=Users` and
        // `--resource=User` from producing two different answers.
        $resourceName = Str::studly(Str::singular((string) $this->option('resource')));
        $plural = Str::pluralStudly($resourceName);
        $resourceClass = $resourceName.'Resource';

        if ($panel === '' || $resourceName === '') {
            $this->components->error('The --panel and --resource options are both required.');

            return self::FAILURE;
        }

        if (! in_array($type, self::TYPES, true)) {
            $this->components->error(sprintf(
                'Unknown relation type [%s]. Valid types are: %s.',
                $type,
                implode(', ', self::TYPES),
            ));

            return self::FAILURE;
        }

        $relationship = Str::camel((string) $this->argument('name'));
        $class = Str::studly($relationship);
        $manyToMany = $type === 'belongs-to-many';
        $softDeletes = (bool) $this->option('soft-deletes');

        $this->writeStub(
            'relation-manager',
            app_path("Panels/{$panel}/Resources/{$plural}/RelationManagers/{$class}RelationManager.php"),
            [
                'panel' => $panel,
                'plural' => $plural,
                'resource' => $resourceClass,
                'class' => $class,
                'relationship' => $relationship,
                'imports' => $this->imports($panel, $plural, $resourceClass, $manyToMany, $softDeletes),
                'filters' => $this->filters($softDeletes),
                'recordActions' => $this->recordActions($resourceClass, $manyToMany, $softDeletes),
                'bulkActions' => $this->bulkActions($manyToMany),
                'pivotForm' => $manyToMany ? $this->pivotForm() : '',
            ],
        );

        if ($this->option('page')) {
            $this->writeStub(
                'relation-page',
                app_path("Panels/{$panel}/Resources/{$plural}/Pages/Manage{$plural}{$class}.php"),
                [
                    'panel' => $panel,
                    'plural' => $plural,
                    'resource' => $resourceClass,
                    'class' => $class,
                    'relationship' => $relationship,
                ],
            );
        }

        $this->components->info(sprintf(
            'Add %sRelationManager::class to %s::relationManagers(). Nothing is registered until you do.',
            $class,
            $resourceClass,
        ));

        return $this->report();
    }

    private function imports(
        string $panel,
        string $plural,
        string $resourceClass,
        bool $manyToMany,
        bool $softDeletes,
    ): string {
        $namespace = "App\\Panels\\{$panel}\\Resources\\{$plural}";

        $imports = [
            'PandaPanel\Actions\Relations\DeleteRelatedAction',
            'PandaPanel\Actions\Relations\EditRelatedAction',
        ];

        if ($manyToMany) {
            $imports[] = 'PandaPanel\Actions\Relations\DetachAction';
            $imports[] = 'PandaPanel\Actions\Relations\DetachBulkAction';
        }

        if ($softDeletes) {
            $imports[] = 'PandaPanel\Actions\Relations\ForceDeleteAction';
            $imports[] = 'PandaPanel\Actions\Relations\RestoreAction';
            $imports[] = 'PandaPanel\Tables\Filters\TrashedFilter';
        }

        $imports = [
            ...$imports,
            'PandaPanel\Forms\FormSchema',
            'PandaPanel\Resources\RelationManager',
            'PandaPanel\Tables\Columns\TextColumn',
            'PandaPanel\Tables\TableSchema',
            'Illuminate\Database\Eloquent\Model',
            "{$namespace}\\{$resourceClass}",
        ];

        sort($imports);

        return implode("\n", array_map(
            static fn (string $import): string => "use {$import};",
            $imports,
        ));
    }

    private function recordActions(string $resourceClass, bool $manyToMany, bool $softDeletes): string
    {
        $actions = [
            "                EditRelatedAction::make({$resourceClass}::class, self::class, \$owner),",
        ];

        if ($manyToMany) {
            $actions[] = '                DetachAction::make(self::class, $owner),';
        }

        if ($softDeletes) {
            $actions[] = '                RestoreAction::make(self::class, $owner),';
            $actions[] = '                ForceDeleteAction::make(self::class, $owner),';
        }

        $actions[] = '                DeleteRelatedAction::make(self::class, $owner),';

        return implode("\n", $actions);
    }

    /**
     * The trashed filter is what puts a deleted record on screen, so a
     * manager with restore actions and no filter would offer two buttons that
     * can never appear.
     */
    private function filters(bool $softDeletes): string
    {
        return $softDeletes
            ? "                TrashedFilter::make('trashed'),"
            : '                //';
    }

    private function bulkActions(bool $manyToMany): string
    {
        return $manyToMany
            ? '                DetachBulkAction::make(self::class, $owner),'
            : '                //';
    }

    private function pivotForm(): string
    {
        return "\n".<<<'PHP'

    /**
     * The pivot columns an attach or an edit may write. Only fields declared
     * here are validated and persisted to the join row.
     */
    public static function pivotForm(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            //
        ]);
    }
PHP;
    }
}
