<?php

declare(strict_types=1);

namespace PandaPanel\Pages\Settings;

use PandaPanel\Pages\Page;

/**
 * Theme selection. Entirely client-side, so the page ships no props: the
 * choice is held in local storage and a cookie by `useAppearance`.
 */
final class AppearanceSettings extends Page
{
    protected static ?string $slug = 'settings-appearance';

    protected static string $component = 'panel/settings/Appearance';

    protected static ?string $navigationIcon = 'palette';

    protected static int $navigationSort = 30;

    /*
     * Title, subheading and navigation group are read through methods rather
     * than set as static properties, because a property default is evaluated
     * before the translator can answer and would freeze this page's English
     * into every locale.
     */
    public static function title(): string
    {
        return __('panda-panel::pages.appearance.title');
    }

    public static function subheading(): string
    {
        return __('panda-panel::pages.appearance.subheading');
    }

    public static function navigationGroup(): string
    {
        return __('panda-panel::pages.navigation_group.account');
    }

    public static function routePath(): string
    {
        return 'settings/appearance';
    }
}
