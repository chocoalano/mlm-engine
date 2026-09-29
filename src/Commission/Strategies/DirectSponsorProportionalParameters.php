<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies;

use PandaBear\Mlm\Commission\Strategies\Support\SourceEntryFilter;
use PandaBear\Mlm\Commission\Strategies\Support\StrategyParameters;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Finance\FinancialRoundingMode;

/**
 * @internal
 *
 * `direct-sponsor.proportional`'s parameters, read exactly — the one reading
 * both validation and calculation use.
 */
final readonly class DirectSponsorProportionalParameters
{
    public const FIELDS = ['minimum_quantity', 'rounding', 'source_type', 'unit_amount', 'volume_type'];

    private function __construct(
        public SourceEntryFilter $source,
        public FinancialAmount $unitAmount,
        public FinancialRoundingMode $rounding,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidPlanDefinition
     */
    public static function parse(array $parameters): self
    {
        $strategy = DirectSponsorProportionalStrategy::KEY;
        StrategyParameters::exactly($strategy, $parameters, self::FIELDS);

        return new self(
            SourceEntryFilter::parse($strategy, $parameters),
            StrategyParameters::positiveAmount($strategy, 'unit_amount', $parameters['unit_amount']),
            StrategyParameters::rounding($strategy, $parameters['rounding']),
        );
    }
}
