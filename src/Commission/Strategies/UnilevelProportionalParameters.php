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
 * `unilevel.proportional`'s parameters, read exactly — the one reading both
 * validation and calculation use. One rounding mode serves every depth.
 * Levels are keyed by their explicit depth; their order carries no meaning.
 */
final readonly class UnilevelProportionalParameters
{
    public const FIELDS = ['levels', 'minimum_quantity', 'rounding', 'source_type', 'volume_type'];

    /**
     * @param  array<int, UnilevelProportionalLevel>  $levels  by depth
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
        $strategy = UnilevelProportionalStrategy::KEY;
        StrategyParameters::exactly($strategy, $parameters, self::FIELDS);
        $source = SourceEntryFilter::parse($strategy, $parameters);
        $rounding = StrategyParameters::rounding($strategy, $parameters['rounding']);
        $amounts = StrategyParameters::depthLevels($strategy, $parameters['levels'], 'unit_amount', static fn (string $field, mixed $value): FinancialAmount => StrategyParameters::positiveAmount($strategy, $field, $value));
        $levels = [];

        foreach ($amounts as $depth => $unitAmount) {
            $levels[$depth] = new UnilevelProportionalLevel($depth, $unitAmount);
        }

        return new self($source, $rounding, $levels);
    }

    public function maximumDepth(): int
    {
        return max(array_keys($this->levels));
    }
}
