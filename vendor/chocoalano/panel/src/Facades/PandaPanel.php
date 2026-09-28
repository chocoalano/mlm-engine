<?php

declare(strict_types=1);

namespace PandaPanel\Facades;

use Illuminate\Support\Facades\Facade;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;

/**
 * The panel manager, reachable without constructor injection.
 *
 * The `panel()` helper covers the common case — "which panel am I in" — and
 * this covers the rest: registering a panel from a package's own provider,
 * listing them for a switcher, resolving one from a request.
 *
 * @method static Panel registerProvider(class-string<\PandaPanel\Core\PanelProvider> $provider)
 * @method static Panel register(Panel $panel)
 * @method static list<Panel> all()
 * @method static bool has(string $id)
 * @method static Panel get(string $id)
 * @method static Panel|null currentPanel()
 * @method static void setCurrentPanel(Panel|null $panel)
 * @method static Panel|null resolveFromRequest(\Illuminate\Http\Request $request)
 * @method static Panel|null firstAccessibleTo(\Illuminate\Contracts\Auth\Authenticatable|null $user)
 * @method static \PandaPanel\Core\ResourceRegistry resources(Panel|string $panel)
 * @method static \PandaPanel\Core\PageRegistry pages(Panel|string $panel)
 * @method static \PandaPanel\Core\WidgetRegistry widgets(Panel|string $panel)
 * @method static \PandaPanel\Core\NavigationRegistry navigation(Panel|string $panel)
 *
 * @see PanelManager
 */
final class PandaPanel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PanelManager::class;
    }
}
