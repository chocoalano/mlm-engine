<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies\Support;

use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Volume\Quantity;
use PandaBear\Mlm\Volume\VolumeInput;

/**
 * @internal
 *
 * Which business entries can earn a fixed award: those of one volume type,
 * from one source type, whose quantity reaches a minimum. The quantity is a
 * measurement that makes an entry eligible — never money.
 */
final readonly class SourceEntryFilter
{
    private function __construct(
        public string $volumeType,
        public string $sourceType,
        public Quantity $minimumQuantity,
    ) {}

    /**
     * From the strategy's `volume_type`, `source_type` and
     * `minimum_quantity` parameters.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidPlanDefinition
     */
    public static function parse(string $strategy, array $parameters): self
    {
        foreach (['volume_type' => 'type', 'source_type' => 'source type'] as $field => $meaning) {
            try {
                VolumeInput::identifier($meaning, is_string($parameters[$field]) ? $parameters[$field] : '');
            } catch (InvalidVolumeEntry $exception) {
                throw StrategyParameters::invalid($strategy, "\"{$field}\": {$exception->getMessage()}");
            }
        }

        try {
            $minimum = VolumeInput::storable(Quantity::of($parameters['minimum_quantity']));
        } catch (InvalidVolumeEntry $exception) {
            throw StrategyParameters::invalid($strategy, "\"minimum_quantity\": {$exception->getMessage()}");
        }

        if ($minimum->isNegative()) {
            throw StrategyParameters::invalid($strategy, "\"minimum_quantity\" is zero or more; {$minimum} given.");
        }

        return new self($parameters['volume_type'], $parameters['source_type'], $minimum);
    }

    /**
     * Whether the entry's quantity reaches the minimum, compared exactly.
     */
    public function reachesMinimum(VolumeEntry $entry): bool
    {
        return $entry->quantity->compare($this->minimumQuantity) >= 0;
    }
}
