<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning;

use PandaBear\Mlm\Exceptions\DuplicatePlanComponentDriver;
use PandaBear\Mlm\Exceptions\InvalidPlanComponentDriver;
use PandaBear\Mlm\Exceptions\UnknownPlanComponentDriver;

/**
 * The trusted component drivers a plan definition can select, by key.
 *
 * Bound as an application singleton: its registrations are configuration,
 * made once while the application boots. The package registers its own rank
 * ladder, "rank.ladder"; an application or package adds its own drivers from
 * a service provider:
 *
 *   $this->callAfterResolving(PlanComponentDriverRegistry::class, function (PlanComponentDriverRegistry $drivers): void {
 *       $drivers->register(new AcmeBonusDriver);
 *   });
 *
 * Reading and validating plans never change the registry. A key is
 * registered once: a second claim to it is refused rather than replacing the
 * first, so which code runs never depends on the order providers boot in.
 */
final class PlanComponentDriverRegistry
{
    /**
     * @var array<string, PlanComponentDriver>
     */
    private array $drivers = [];

    /**
     * @throws InvalidPlanComponentDriver for a key outside the key format
     * @throws DuplicatePlanComponentDriver for a key already registered
     */
    public function register(PlanComponentDriver $driver): void
    {
        $key = $driver->key();

        if (! DefinitionInput::isIdentifier($key, DefinitionInput::DRIVER_LENGTH)) {
            throw InvalidPlanComponentDriver::key($key, $driver);
        }

        if (isset($this->drivers[$key])) {
            throw DuplicatePlanComponentDriver::forKey($key, $this->drivers[$key], $driver);
        }

        $this->drivers[$key] = $driver;
    }

    public function has(string $key): bool
    {
        return isset($this->drivers[$key]);
    }

    /**
     * @throws UnknownPlanComponentDriver
     */
    public function get(string $key): PlanComponentDriver
    {
        return $this->drivers[$key] ?? throw UnknownPlanComponentDriver::forKey($key, $this->keys());
    }

    /**
     * @return list<string> sorted
     */
    public function keys(): array
    {
        $keys = array_keys($this->drivers);
        sort($keys);

        return $keys;
    }
}
