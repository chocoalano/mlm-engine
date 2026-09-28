<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Console\Command;
use PandaPanel\Cache\PanelManifest;

/**
 * Removes the panel manifest.
 *
 * Idempotent: a missing manifest is success, not an error, so `optimize:clear`
 * on a fresh checkout does not fail.
 */
final class ClearPanelsCommand extends Command
{
    protected $signature = 'panel:clear';

    protected $description = 'Remove the cached panel manifest';

    public function handle(PanelManifest $manifest): int
    {
        $manifest->clear();

        $this->components->info('Panel manifest cleared.');

        return self::SUCCESS;
    }
}
