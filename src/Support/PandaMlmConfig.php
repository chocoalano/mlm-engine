<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Support;

use Illuminate\Contracts\Config\Repository;
use PandaBear\Mlm\Exceptions\InvalidMlmConfiguration;

/**
 * The package's technical configuration, built from `config('mlm')` and
 * typed, so nothing else in the package reads config keys by string.
 *
 * Infrastructure only. Business plan rules are versioned in the database and
 * never appear here.
 */
final readonly class PandaMlmConfig
{
    public function __construct(
        private ?string $databaseConnection,
        private ?string $queueConnection,
        private string $queueName,
        private ?string $cacheStore,
        private string $cachePrefix,
    ) {
        // An empty queue name or cache prefix silently lands jobs and keys in
        // the application's own namespace instead of the package's.
        if (trim($queueName) === '') {
            throw InvalidMlmConfiguration::mustBe('mlm.queue.name', 'a non-empty string', $queueName);
        }

        if (trim($cachePrefix) === '') {
            throw InvalidMlmConfiguration::mustBe('mlm.cache.prefix', 'a non-empty string', $cachePrefix);
        }
    }

    public static function fromConfig(Repository $config): self
    {
        return new self(
            databaseConnection: self::optionalString($config, 'mlm.database.connection'),
            queueConnection: self::optionalString($config, 'mlm.queue.connection'),
            queueName: self::requiredString($config, 'mlm.queue.name'),
            cacheStore: self::optionalString($config, 'mlm.cache.store'),
            cachePrefix: self::requiredString($config, 'mlm.cache.prefix'),
        );
    }

    /** Null means the application's default connection. */
    public function databaseConnection(): ?string
    {
        return $this->databaseConnection;
    }

    /** Null means the application's default connection. */
    public function queueConnection(): ?string
    {
        return $this->queueConnection;
    }

    public function queueName(): string
    {
        return $this->queueName;
    }

    /** Null means the application's default store. */
    public function cacheStore(): ?string
    {
        return $this->cacheStore;
    }

    public function cachePrefix(): string
    {
        return $this->cachePrefix;
    }

    /**
     * An unset environment variable is null and an empty one is '' — both
     * mean "use the application's default".
     */
    private static function optionalString(Repository $config, string $key): ?string
    {
        $value = $config->get($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidMlmConfiguration::mustBe($key, 'a string or null', $value);
        }

        return $value;
    }

    private static function requiredString(Repository $config, string $key): string
    {
        $value = $config->get($key);

        if (! is_string($value)) {
            throw InvalidMlmConfiguration::mustBe($key, 'a non-empty string', $value);
        }

        return $value;
    }
}
