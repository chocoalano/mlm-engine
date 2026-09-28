<?php

declare(strict_types=1);

namespace PandaPanel\Widgets;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;
use PandaPanel\Widgets\Enums\WidgetType;

/**
 * A table on a dashboard, built by the table builder.
 *
 * The same `TableSchema` a resource index uses, and now the same `TableQuery`
 * too: search, sorting, and pagination all work, and a column renders
 * identically here and on an index.
 *
 * The state is namespaced by widget id (`widgets[recent-orders][page]`), which
 * is what makes it possible at all — two table widgets on one dashboard would
 * otherwise fight over `page`, and the namespace is exactly the arrangement a
 * relation manager already uses for the same reason.
 *
 * What it still is not: a resource index. No bulk actions, no column manager,
 * no filter tabs. A widget is a summary that can be sorted and searched, not a
 * second place records are managed from.
 */
abstract class TableWidget extends Widget
{
    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 2, 'xl' => 2];

    /*
     * Empty rather than the sentence itself: a static property is initialized
     * before the translator can answer, so what "unset" means is decided
     * where it is read. Still typed `string` and not `?string`, because a
     * widget that already declares `protected static string $emptyMessage`
     * would fatal on a redeclaration that widened the type.
     */
    protected static string $emptyMessage = '';

    /**
     * Rows per page. A dashboard table is read at a glance, so the default is
     * short and the widget raises it deliberately.
     */
    protected static int $perPage = 5;

    public static function type(): WidgetType
    {
        return WidgetType::Table;
    }

    abstract public function table(TableSchema $table): TableSchema;

    /**
     * The records this table draws from.
     *
     * A query rather than a collection, so the table builder can search, sort,
     * and page it. Narrow it here — a dashboard table showing every order ever
     * placed is a dashboard nobody opens twice.
     *
     * @return Builder<covariant Model>
     */
    abstract public function query(): Builder;

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $schema = $this->table(TableSchema::make())
            ->perPageOptions([static::$perPage])
            ->defaultPerPage(static::$perPage);

        $query = new TableQuery($schema, request(), static::stateNamespace());

        $records = $query->paginate($this->query());
        $visible = $query->state()['columns']['visible'];

        return [
            'columns' => $schema->toArray()['columns'],
            'rows' => array_values(array_map(
                static fn (Model $record): array => $schema->toRow($record, null, $visible),
                $records->items(),
            )),
            'emptyMessage' => static::$emptyMessage === ''
                ? __('panda-panel::pages.widgets.table_empty')
                : static::$emptyMessage,
            // The same shapes the resource index sends, so the renderer is the
            // same component rather than a second one that drifts.
            'state' => $query->state(),
            // The same keys a resource index sends, so the frontend renders
            // through the same pagination component rather than a second one.
            'pagination' => [
                'page' => $records->currentPage(),
                'perPage' => $records->perPage(),
                'total' => $records->total(),
                'lastPage' => $records->lastPage(),
                'from' => $records->firstItem() ?? 0,
                'to' => $records->lastItem() ?? 0,
            ],
            'namespace' => static::stateNamespace(),
            'searchable' => $schema->isSearchable(),
        ];
    }

    /**
     * Where this widget's table state lives in the query string.
     *
     * Two table widgets on one dashboard have to be told apart, and the widget
     * id is the only thing that already identifies one.
     */
    public static function stateNamespace(): string
    {
        return 'widgets.'.Str::kebab(class_basename(static::class));
    }
}
