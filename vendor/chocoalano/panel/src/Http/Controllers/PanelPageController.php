<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Inertia\Response;
use PandaPanel\Pages\Page;

/**
 * Renders a standalone page.
 *
 * The page class is bound at route registration, so this controller never
 * resolves a class name from the request.
 */
final class PanelPageController
{
    /**
     * @param  class-string<Page>  $page
     */
    public function __invoke(string $page): Response
    {
        return (new $page)->render();
    }
}
