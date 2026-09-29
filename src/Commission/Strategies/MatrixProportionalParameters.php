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
 * `matrix.proportional`'s parameters, read exactly — the one reading both
 * validation and calculation use, with the same rules as
 * `unilevel.proportional`. One rounding mode serves every depth; levels are
 * keyed by their explicit matrix depth.
 */
final readonly class MatrixProportionalParameters
{
    public const FIELDS = ['levels', 'minimum_quantity', 'rounding', 'source_type', 'volume_type'];

    /**
     * @param  array<int, FinancialAmount>  $levels  the amount per unit, by depth
     */
    private function __construct(
        public SourceEntryFilter $source,
        public FinancialRoundingMode $rounding,
        public array $levels,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidPlanDefinition
     */
    public static function parse(array $parameters): self
    {
        $strategy = MatrixProportionalStrategy::KEY;
        StrategyParameters::exactly($strategy, $parameters, self::FIELDS);

        return new self(
            SourceEntryFilter::parse($strategy, $parameters),
            StrategyParameters::rounding($strategy, $parameters['rounding']),
            StrategyParameters::depthLevels($strategy, $parameters['levels'], 'unit_amount', static fn (string $field, mixed $value): FinancialAmount => StrategyParameters::positiveAmount($strategy, $field, $value)),
        );
    }

    public function maximumDepth(): int
    {
        return max(array_keys($this->levels));
    }
}
