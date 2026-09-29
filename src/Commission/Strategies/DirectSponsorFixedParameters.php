<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies;

use PandaBear\Mlm\Commission\Strategies\Support\SourceEntryFilter;
use PandaBear\Mlm\Commission\Strategies\Support\StrategyParameters;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Finance\FinancialAmount;

/**
 * @internal
 *
 * `direct-sponsor.fixed`'s parameters, read exactly — the one reading both
 * validation and calculation use.
 */
final readonly class DirectSponsorFixedParameters
{
    public const FIELDS = ['amount', 'minimum_quantity', 'source_type', 'volume_type'];

    private function __construct(
        public SourceEntryFilter $source,
        public FinancialAmount $amount,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidPlanDefinition
     */
    public static function parse(array $parameters): self
    {
        StrategyParameters::exactly(DirectSponsorFixedStrategy::KEY, $parameters, self::FIELDS);

        return new self(
            SourceEntryFilter::parse(DirectSponsorFixedStrategy::KEY, $parameters),
            StrategyParameters::award(DirectSponsorFixedStrategy::KEY, 'amount', $parameters['amount']),
        );
    }
}
