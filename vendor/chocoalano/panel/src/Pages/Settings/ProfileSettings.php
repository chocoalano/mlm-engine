<?php

declare(strict_types=1);

namespace PandaPanel\Pages\Settings;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use PandaPanel\Pages\Page;

/**
 * The signed-in user's profile, inside whichever panel they are in.
 *
 * The page renders only. Saving still goes to the application's own
 * `ProfileController`, so there remains exactly one place that writes a
 * profile no matter which shell the form was submitted from.
 */
final class ProfileSettings extends Page
{
    protected static ?string $slug = 'settings-profile';

    protected static string $component = 'panel/settings/Profile';

    protected static ?string $navigationIcon = 'user';

    protected static int $navigationSort = 10;

    /*
     * Title, subheading and navigation group are read through methods rather
     * than set as static properties, because a property default is evaluated
     * before the translator can answer and would freeze this page's English
     * into every locale.
     */
    public static function title(): string
    {
        return __('panda-panel::pages.profile.title');
    }

    public static function subheading(): string
    {
        return __('panda-panel::pages.profile.subheading');
    }

    public static function navigationGroup(): string
    {
        return __('panda-panel::pages.navigation_group.account');
    }

    /**
     * A nested path while the slug stays one segment: the slug is a route
     * name and a registry key, the path is what the address bar shows.
     */
    public static function routePath(): string
    {
        return 'settings/profile';
    }

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return [
            'mustVerifyEmail' => Auth::user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ];
    }
}
