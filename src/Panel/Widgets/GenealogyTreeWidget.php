<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Widgets;

use Illuminate\Database\Eloquent\Builder;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Pages\GenealogyExplorer;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\GenealogyNode;
use PandaBear\Mlm\Panel\Support\GenealogyTree;
use PandaPanel\Tables\ArrayTableData;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;
use PandaPanel\Widgets\TableWidget;

/**
 * The anchor's network as an indented tree: each member under its parent,
 * its depth, and its binary side or matrix slot where the network has one.
 * Drawn with the panel's own table — Panda Panel resolves Vue components
 * from the application's tree, not from a plugin — so the hierarchy is
 * carried by order and indentation.
 */
final class GenealogyTreeWidget extends TableWidget
{
    protected static int|string|array $columnSpan = 'full';

    protected static int $sort = 1;

    public static function canView(): bool
    {
        return MlmPermission::allows(MlmPermission::NETWORK_VIEW);
    }

    public function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('tree')->label(Display::field('member')),
                NumberColumn::make('depth')->label(Display::field('depth')),
                TextColumn::make('parent_code')->label(Display::field('relationship'))->placeholder(Display::none()),
                TextColumn::make('position')->label(Display::field('position'))->placeholder(Display::none()),
                TextColumn::make('joined_at')->label(Display::field('joined_at')),
            ])
            ->perPageOptions([GenealogyTree::NODE_CAP])
            ->defaultPerPage(GenealogyTree::NODE_CAP);
    }

    /**
     * Unused: the rows are computed, not queried. The table builder needs a
     * query for a widget it pages itself, and this one pages an array.
     *
     * @return Builder<Member>
     */
    public function query(): Builder
    {
        return Member::query()->whereRaw('1 = 0');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $reading = GenealogyExplorer::reading($this->filters());
        $schema = $this->table(TableSchema::make());
        $nodes = array_map(static fn (array $node): GenealogyNode => new GenealogyNode([
            ...$node,
            'tree' => str_repeat('— ', $node['depth'] - 1).$node['member_code'],
            'position' => match (true) {
                $node['side'] !== null => __("mlm::mlm.values.side_{$node['side']}"),
                $node['slot'] !== null => __('mlm::mlm.values.slot', ['slot' => $node['slot']]),
                default => null,
            },
        ]), $reading['tree']['nodes'] ?? []);

        $table = ArrayTableData::make($schema, $nodes, request(), self::stateNamespace());
        $records = $table->paginate();

        return [
            'columns' => $schema->toArray()['columns'],
            'rows' => $table->rows($records),
            'emptyMessage' => $reading === null ? __('mlm::mlm.explorer.choose_anchor') : __('mlm::mlm.explorer.no_relatives'),
            'state' => $table->state(),
            'pagination' => $table->pagination($records),
            'namespace' => self::stateNamespace(),
            'searchable' => false,
        ];
    }
}
