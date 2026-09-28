<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Console\Command;
use PandaPanel\Cache\PanelManifest;
use PandaPanel\Core\PanelRegistry;

/**
 * Builds the panel manifest.
 *
 * Discovery runs here so it never has to run in a request. Registered by the
 * service provider as an `optimize` hook, so `php artisan optimize` includes
 * it alongside the config and route caches.
 */
final class CachePanelsCommand extends Command
{
    protected $signature = 'panel:cache';

    protected $description = 'Discover panel resources, pages, and widgets, and cache the manifest';

    public function handle(PanelManifest $manifest, PanelRegistry $registry): int
    {
        $panels = $manifest->write($registry);

        $counts = ['resources' => 0, 'pages' => 0, 'widgets' => 0];

        foreach ($panels as $panel) {
            foreach ($counts as $key => $value) {
                $counts[$key] = $value + count($panel[$key]);
            }
        }

        $this->components->info(sprintf(
            'Panels cached: %d panels, %d resources, %d pages, %d widgets.',
            count($panels),
            $counts['resources'],
            $counts['pages'],
            $counts['widgets'],
        ));

        return self::SUCCESS;
    }
}
