<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use PandaBear\Mlm\PandaMlmServiceProvider;
use PandaPanel\PandaPanelServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Testbench ignores package discovery, so both providers are listed by
     * hand: Panda Panel's for `PanelManager` and `panel:plugins`, and this
     * package's own.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PandaPanelServiceProvider::class,
            PandaMlmServiceProvider::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function composerJson(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__).'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
