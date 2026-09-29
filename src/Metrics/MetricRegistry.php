<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Metrics;

use PandaBear\Mlm\Exceptions\DuplicateMetric;
use PandaBear\Mlm\Exceptions\InvalidMetric;
use PandaBear\Mlm\Exceptions\UnknownMetric;

/**
 * The trusted metrics an application can resolve, by key.
 *
 * Bound as an application singleton: its registrations are configuration,
 * made once while the application boots — the package's built-ins first,
 * then any a service provider adds:
 *
 *   $this->callAfterResolving(MetricRegistry::class, function (MetricRegistry $metrics): void {
 *       $metrics->register(new AcmeRetentionMetric);
 *   });
 *
 * Resolving a metric never changes the registry. Choose namespaced keys
 * ("acme.retention") — a key is registered once, and a second claim to it
 * is refused rather than replacing the first, so which code runs never
 * depends on the order service providers boot in.
 */
final class MetricRegistry
{
    /**
     * 1–100 characters: lowercase letters, digits, ".", "-" and "_",
     * starting with a letter or digit.
     */
    private const KEY = '/^[a-z0-9][a-z0-9._-]{0,99}$/D';

    /**
     * @var array<string, Metric>
     */
    private array $metrics = [];

    /**
     * @throws InvalidMetric for a key outside the key format
     * @throws DuplicateMetric for a key already registered
     */
    public function register(Metric $metric): void
    {
        $key = $metric->key();

        if (preg_match(self::KEY, $key) !== 1) {
            throw InvalidMetric::key($key, $metric);
        }

        if (isset($this->metrics[$key])) {
            throw DuplicateMetric::forKey($key, $this->metrics[$key], $metric);
        }

        $this->metrics[$key] = $metric;
    }

    public function has(string $key): bool
    {
        return isset($this->metrics[$key]);
    }

    /**
     * @throws UnknownMetric
     */
    public function get(string $key): Metric
    {
        return $this->metrics[$key] ?? throw UnknownMetric::forKey($key, $this->keys());
    }

    /**
     * @return list<string> sorted
     */
    public function keys(): array
    {
        $keys = array_keys($this->metrics);
        sort($keys);

        return $keys;
    }
}
