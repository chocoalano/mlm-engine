<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Pairing;

use PandaBear\Mlm\Commission\Strategies\Support\StrategyParameters;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;
use PandaBear\Mlm\Volume\Quantity;
use PandaBear\Mlm\Volume\VolumeInput;

/**
 * @internal
 *
 * A binary pairing component's parameters, read exactly — the one reading
 * both validation and calculation use: the volume type that feeds the
 * legs, the exact quantity one pair takes from each leg, and the award.
 */
final readonly class BinaryPairingParameters
{
    public const FIXED = ['amount_per_pair', 'pair_quantity', 'volume_type'];

    public const PROPORTIONAL = ['pair_quantity', 'rounding', 'unit_amount', 'volume_type'];

    private function __construct(
        public string $volumeType,
        public Quantity $pairQuantity,
        public BinaryPairingAward $award,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidPlanDefinition
     */
    public static function fixed(string $strategy, array $parameters): self
    {
        StrategyParameters::exactly($strategy, $parameters, self::FIXED);

        return new self(
            self::volumeType($strategy, $parameters['volume_type']),
            self::pairQuantity($strategy, $parameters['pair_quantity']),
            new FixedPairAward(StrategyParameters::award($strategy, 'amount_per_pair', $parameters['amount_per_pair'])),
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidPlanDefinition
     */
    public static function proportional(string $strategy, array $parameters): self
    {
        StrategyParameters::exactly($strategy, $parameters, self::PROPORTIONAL);

        return new self(
            self::volumeType($strategy, $parameters['volume_type']),
            self::pairQuantity($strategy, $parameters['pair_quantity']),
            new ProportionalPairAward(
                StrategyParameters::positiveAmount($strategy, 'unit_amount', $parameters['unit_amount']),
                StrategyParameters::rounding($strategy, $parameters['rounding']),
            ),
        );
    }

    private static function volumeType(string $strategy, mixed $value): string
    {
        try {
            return VolumeInput::identifier('type', is_string($value) ? $value : '');
        } catch (InvalidVolumeEntry $exception) {
            throw StrategyParameters::invalid($strategy, "\"volume_type\": {$exception->getMessage()}");
        }
    }

    /**
     * Exact and strictly positive, at most six decimal places: never a float,
     * never rounded.
     */
    private static function pairQuantity(string $strategy, mixed $value): Quantity
    {
        try {
            $quantity = Quantity::of($value);
        } catch (InvalidVolumeEntry $exception) {
            throw StrategyParameters::invalid($strategy, "\"pair_quantity\": {$exception->getMessage()}");
        }

        if (! $quantity->isPositive()) {
            throw StrategyParameters::invalid($strategy, "\"pair_quantity\" is strictly positive; {$quantity} given.");
        }

        return $quantity;
    }
}
