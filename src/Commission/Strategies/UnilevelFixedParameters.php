<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies;

use PandaBear\Mlm\Commission\Strategies\Support\SourceEntryFilter;
use PandaBear\Mlm\Commission\Strategies\Support\StrategyParameters;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;

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

    public const LEVEL_FIELDS = ['amount', 'depth'];

    public const MAX_DEPTH = 100;

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
        $listed = $parameters['levels'];

        if (! is_array($listed) || $listed === [] || ! array_is_list($listed)) {
            throw StrategyParameters::invalid($strategy, '"levels" is a non-empty list of {depth, amount}.');
        }

        $levels = [];

        foreach ($listed as $index => $level) {
            if (! is_array($level) || ($level !== [] && array_is_list($level))) {
                throw StrategyParameters::invalid($strategy, "\"levels\"[{$index}] is an object of depth and amount.");
            }

            StrategyParameters::exactly($strategy, $level, self::LEVEL_FIELDS, "\"levels\"[{$index}] fields");
            $depth = $level['depth'];

            if (! is_int($depth) || $depth < 1 || $depth > self::MAX_DEPTH) {
                throw StrategyParameters::invalid($strategy, sprintf('"levels"[%d].depth is a whole number from 1 to %d; %s given.', $index, self::MAX_DEPTH, get_debug_type($depth) === 'int' ? $depth : get_debug_type($depth)));
            }

            if (isset($levels[$depth])) {
                throw StrategyParameters::invalid($strategy, "\"levels\" names depth {$depth} more than once.");
            }

            $levels[$depth] = new UnilevelFixedLevel($depth, StrategyParameters::award($strategy, "levels[{$index}].amount", $level['amount']));
        }

        ksort($levels);

        return new self($source, $levels);
    }

    public function maximumDepth(): int
    {
        return max(array_keys($this->levels));
    }
}
