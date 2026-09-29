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
 * `unilevel.fixed`'s parameters, read exactly — the one reading both
 * validation and calculation use. Levels are keyed by their explicit depth:
 * the order they are listed in carries no meaning, and depths not listed
 * earn nothing.
 */
final readonly class UnilevelFixedParameters
{
    public const FIELDS = ['levels', 'minimum_quantity', 'source_type', 'volume_type'];

    public const MAX_DEPTH = StrategyParameters::MAX_DEPTH;

    /**
     * @param  array<int, UnilevelFixedLevel>  $levels  by depth
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
        $strategy = UnilevelFixedStrategy::KEY;
        StrategyParameters::exactly($strategy, $parameters, self::FIELDS);
        $source = SourceEntryFilter::parse($strategy, $parameters);
        $amounts = StrategyParameters::depthLevels($strategy, $parameters['levels'], 'amount', static fn (string $field, mixed $value): FinancialAmount => StrategyParameters::award($strategy, $field, $value));
        $levels = [];

        foreach ($amounts as $depth => $amount) {
            $levels[$depth] = new UnilevelFixedLevel($depth, $amount);
        }

        return new self($source, $levels);
    }

    public function maximumDepth(): int
    {
        return max(array_keys($this->levels));
    }
}
