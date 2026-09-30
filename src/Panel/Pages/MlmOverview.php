<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Pages;

use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\MlmNavigation;
use PandaBear\Mlm\Panel\Widgets\MlmOverviewStats;
use PandaPanel\Pages\Page;

/**
 * What needs an operator now, across every program. Global by design: a
 * program's own detail lives on its program page, so there is no program
 * selector here.
 */
final class MlmOverview extends Page
{
    protected static ?string $slug = 'mlm-overview';

    protected static ?string $navigationIcon = 'layout-grid';

    protected static int $navigationSort = MlmNavigation::OVERVIEW;

    public static function title(): string
    {
        return __('mlm::mlm.overview.title');
    }

    public static function subheading(): ?string
    {
        return __('mlm::mlm.overview.subheading');
    }

    public static function navigationGroup(): string
    {
        return MlmNavigation::group();
    }

    public static function canAccess(): bool
    {
        return MlmPermission::allows(MlmPermission::DASHBOARD_VIEW);
    }

    /**
     * @return list<class-string<MlmOverviewStats>>
     */
    public function widgets(): array
    {
        return [MlmOverviewStats::class];
    }
}
