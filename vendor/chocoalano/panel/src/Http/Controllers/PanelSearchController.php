<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Search\GlobalSearch;

/**
 * Answers the command palette.
 *
 * JSON rather than an Inertia page: the palette asks while the user is
 * typing, and re-rendering the page they are on to answer would be absurd.
 * It sits behind the panel's own middleware, so an unauthenticated or
 * unauthorized request never reaches the search.
 */
final class PanelSearchController
{
    public function __construct(
        private readonly PanelManager $manager,
        private readonly GlobalSearch $search,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $panel = $this->manager->currentPanel()
            ?? throw PanelRegistrationException::noCurrentPanel();

        return response()->json([
            'groups' => $this->search->for($panel, (string) ($validated['q'] ?? '')),
        ]);
    }
}
