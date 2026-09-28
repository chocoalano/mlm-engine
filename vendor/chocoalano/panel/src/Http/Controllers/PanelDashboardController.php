<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Inertia\Response;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;

/**
 * Renders the panel's landing page.
 *
 * A controller rather than a route closure so panel routes stay
 * route-cacheable. The work itself belongs to the Page class the panel
 * nominated, which is why there is almost nothing here.
 */
final class PanelDashboardController
{
    public function __construct(private readonly PanelManager $manager) {}

    public function __invoke(): Response
    {
        $panel = $this->manager->currentPanel()
            ?? throw PanelRegistrationException::noCurrentPanel();

        $page = $panel->getDashboard();

        return (new $page)->render();
    }
}
