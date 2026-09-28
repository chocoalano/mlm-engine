<?php

declare(strict_types=1);

namespace PandaPanel\Discovery;

use Illuminate\Support\Facades\File;
use PandaPanel\Contracts\PageContract;
use PandaPanel\Contracts\ResourceContract;
use PandaPanel\Contracts\WidgetContract;
use PandaPanel\Core\Panel;
use ReflectionClass;

/**
 * Finds resource, page, and widget classes under a panel's declared paths.
 *
 * Deterministic: results are sorted by class name, so two machines with
 * different filesystem orderings produce the same manifest.
 *
 * A class is included only if it is concrete and implements the expected
 * contract. Anything else is skipped silently rather than failing the boot,
 * because a directory can legitimately hold a base class or a trait.
 */
final class PanelDiscoverer
{
    /**
     * @return list<class-string<ResourceContract>>
     */
    public function resources(Panel $panel): array
    {
        /** @var list<class-string<ResourceContract>> $classes */
        $classes = $this->discover($panel->getResourceDiscoveryPaths(), ResourceContract::class);

        return $classes;
    }

    /**
     * @return list<class-string<PageContract>>
     */
    public function pages(Panel $panel): array
    {
        /** @var list<class-string<PageContract>> $classes */
        $classes = $this->discover($panel->getPageDiscoveryPaths(), PageContract::class);

        return $classes;
    }

    /**
     * @return list<class-string<WidgetContract>>
     */
    public function widgets(Panel $panel): array
    {
        /** @var list<class-string<WidgetContract>> $classes */
        $classes = $this->discover($panel->getWidgetDiscoveryPaths(), WidgetContract::class);

        return $classes;
    }

    /**
     * @param  list<string>  $paths
     * @param  class-string  $contract
     * @return list<class-string>
     */
    private function discover(array $paths, string $contract): array
    {
        $classes = [];

        foreach ($paths as $path) {
            if (! File::isDirectory($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $class = ClassResolver::forPath($file->getPathname());

                if ($class !== null && $this->qualifies($class, $contract)) {
                    $classes[] = $class;
                }
            }
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }

    /**
     * @param  class-string  $class
     * @param  class-string  $contract
     */
    private function qualifies(string $class, string $contract): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        return ! $reflection->isAbstract()
            && ! $reflection->isInterface()
            && $reflection->implementsInterface($contract);
    }
}
