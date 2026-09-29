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
 * `matrix.fixed`'s parameters, read exactly — the one reading both
 * validation and calculation use, with the same rules as `unilevel.fixed`.
 * Levels are keyed by their explicit matrix depth: the order they are listed
 * in carries no meaning, and depths not listed earn nothing.
 */
final readonly class MatrixFixedParameters
{
    public const FIELDS = ['levels', 'minimum_quantity', 'source_type', 'volume_type'];

    /**
     * @param  array<int, FinancialAmount>  $levels  the award, by depth
     */
    private function __construct(
        public SourceEntryFilter $source,
        public array $levels,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidPlanDefinition
     */
    public static function parse(array $parameters): self
    {
        $strategy = MatrixFixedStrategy::KEY;
        StrategyParameters::exactly($strategy, $parameters, self::FIELDS);

        return new self(
            SourceEntryFilter::parse($strategy, $parameters),
            StrategyParameters::depthLevels($strategy, $parameters['levels'], 'amount', static fn (string $field, mixed $value): FinancialAmount => StrategyParameters::award($strategy, $field, $value)),
        );
    }

    public function maximumDepth(): int
    {
        return max(array_keys($this->levels));
    }
}
