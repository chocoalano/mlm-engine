<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use PandaBear\Mlm\PandaMlmServiceProvider;

final class PandaMlmServiceProviderTest extends TestCase
{
    public function test_the_application_loads_the_provider(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(PandaMlmServiceProvider::class));
    }

    public function test_composer_declares_the_provider_for_package_discovery(): void
    {
        // `TestCase` registers the provider by hand, which would hide a typo
        // in the entry an application's package discovery actually reads.
        $this->assertContains(
            PandaMlmServiceProvider::class,
            $this->composerJson()['extra']['laravel']['providers'],
        );
    }
}
