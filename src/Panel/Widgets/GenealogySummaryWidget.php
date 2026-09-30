<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Widgets;

use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Pages\GenealogyExplorer;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\GenealogyTree;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

/**
 * What the explorer is showing: the anchor and the line above it, how many
 * relatives, and whether the node cap cut the depth short.
 */
final class GenealogySummaryWidget extends StatsWidget
{
    protected static int|string|array $columnSpan = 'full';

    protected static int $sort = 0;

    public static function canView(): bool
    {
        return MlmPermission::allows(MlmPermission::NETWORK_VIEW);
    }

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        $reading = GenealogyExplorer::reading($this->filters());

        if ($reading === null) {
            return [Stat::make(__('mlm::mlm.explorer.anchor'), Display::none())->description(__('mlm::mlm.explorer.choose_anchor'))];
        }

        $tree = $reading['tree'];

        return [
            Stat::make(__('mlm::mlm.explorer.anchor'), $reading['anchor']->member_code)
                ->description($tree['ancestors'] === []
                    ? __('mlm::mlm.explorer.root')
                    : __('mlm::mlm.explorer.above', ['line' => implode(' ← ', $tree['ancestors'])])),
            Stat::make(__('mlm::mlm.explorer.nodes'), count($tree['nodes']))
                ->description(__('mlm::mlm.explorer.moment', [
                    'network' => __("mlm::mlm.explorer.networks.{$reading['network']}"),
                    'moment' => $reading['at'] === null ? __('mlm::mlm.explorer.now') : Display::moment($reading['at']),
                ])),
            Stat::make(__('mlm::mlm.explorer.levels'), $tree['shown_depth'])
                ->description($tree['truncated']
                    ? __('mlm::mlm.explorer.truncated', ['total' => $tree['within_requested_depth'], 'depth' => $tree['requested_depth'], 'cap' => GenealogyTree::NODE_CAP])
                    : __('mlm::mlm.explorer.complete', ['depth' => $tree['requested_depth']]))
                ->color($tree['truncated'] ? StatColor::Warning : StatColor::Default),
        ];
    }
}
