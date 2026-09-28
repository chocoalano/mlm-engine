<?php

declare(strict_types=1);

use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;

if (! function_exists('panel')) {
    /**
     * Resolve a panel.
     *
     * Without an id, returns the panel resolved for the current request, or
     * null outside a panel. With an id, returns that panel and throws when it
     * is not registered, because an unknown panel id is a developer error.
     *
     * @throws PanelRegistrationException
     */
    function panel(?string $id = null): ?Panel
    {
        $manager = app(PanelManager::class);

        return $id === null
            ? $manager->currentPanel()
            : $manager->get($id);
    }
}
