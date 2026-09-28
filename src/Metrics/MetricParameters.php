<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Metrics;

use PandaBear\Mlm\Exceptions\InvalidMetricParameters;
use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;
use PandaBear\Mlm\Volume\VolumeInput;

/**
 * @internal
 *
 * The parameter checks the built-in metrics share. They refuse rather than
 * guess: nothing is cast, defaulted or ignored.
 */
final class MetricParameters
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  list<string>  $accepted
     */
    public static function refuseUnknown(string $metric, array $parameters, array $accepted): void
    {
        $unknown = array_values(array_diff(array_keys($parameters), $accepted));

        if ($unknown !== []) {
            throw InvalidMetricParameters::unknown($metric, $unknown, $accepted);
        }
    }

    /**
     * The required `type`: a volume type, held to exactly the rule volume
     * itself applies.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function volumeType(string $metric, array $parameters): string
    {
        if (! array_key_exists('type', $parameters)) {
            throw InvalidMetricParameters::missing($metric, 'type');
        }

        $type = $parameters['type'];

        if (! is_string($type)) {
            throw InvalidMetricParameters::invalid($metric, 'type', 'a volume type is a string, '.get_debug_type($type).' given.');
        }

        try {
            return VolumeInput::identifier('type', $type);
        } catch (InvalidVolumeEntry $exception) {
            throw InvalidMetricParameters::invalid($metric, 'type', $exception->getMessage());
        }
    }

    /**
     * The optional `max_depth`: null when it is not given; otherwise a PHP
     * integer of 1 or more — never "2", 2.0, true or an explicit null.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function maxDepth(string $metric, array $parameters): ?int
    {
        if (! array_key_exists('max_depth', $parameters)) {
            return null;
        }

        $depth = $parameters['max_depth'];

        if (! is_int($depth) || $depth < 1) {
            throw InvalidMetricParameters::invalid($metric, 'max_depth', 'a maximum depth is an integer of 1 or more, '.var_export($depth, true).' given.');
        }

        return $depth;
    }
}
