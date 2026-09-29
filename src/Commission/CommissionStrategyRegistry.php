<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use PandaBear\Mlm\Exceptions\DuplicateCommissionStrategy;
use PandaBear\Mlm\Exceptions\InvalidCommissionStrategy;
use PandaBear\Mlm\Exceptions\UnknownCommissionStrategy;
use PandaBear\Mlm\Planning\DefinitionInput;

/**
 * The trusted commission strategies a `commission.strategy` component can
 * select, by key.
 *
 * Bound as an application singleton: its registrations are configuration,
 * made once while the application boots. The package registers its own
 * `direct-sponsor.fixed`, `direct-sponsor.proportional`, `unilevel.fixed` and
 * `unilevel.proportional`; an application or package adds its own from a
 * service provider:
 *
 *   $this->callAfterResolving(CommissionStrategyRegistry::class, function (CommissionStrategyRegistry $strategies): void {
 *       $strategies->register(new AcmeReferralStrategy);
 *   });
 *
 * A key is registered once: a second claim to it is refused rather than
 * replacing the first, so which code runs never depends on the order
 * providers boot in.
 */
final class CommissionStrategyRegistry
{
    /**
     * @var array<string, CommissionStrategy>
     */
    private array $strategies = [];

    /**
     * @throws InvalidCommissionStrategy for a key outside the key format
     * @throws DuplicateCommissionStrategy for a key already registered
     */
    public function register(CommissionStrategy $strategy): void
    {
        $key = $strategy->key();

        if (! DefinitionInput::isIdentifier($key, DefinitionInput::DRIVER_LENGTH)) {
            throw InvalidCommissionStrategy::key($key, $strategy);
        }

        if (isset($this->strategies[$key])) {
            throw DuplicateCommissionStrategy::forKey($key, $this->strategies[$key], $strategy);
        }

        $this->strategies[$key] = $strategy;
    }

    public function has(string $key): bool
    {
        return isset($this->strategies[$key]);
    }

    /**
     * @throws UnknownCommissionStrategy
     */
    public function get(string $key): CommissionStrategy
    {
        return $this->strategies[$key] ?? throw UnknownCommissionStrategy::forKey($key, $this->keys());
    }

    /**
     * @return list<string> sorted
     */
    public function keys(): array
    {
        $keys = array_keys($this->strategies);
        sort($keys);

        return $keys;
    }
}
