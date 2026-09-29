<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Models\Program;

/**
 * What a strategy calculates for: the stored program, the component's
 * definition from its validated version, and the closed range [from, until)
 * — `from` included, `until` excluded. Reads through the package's models and
 * services — on `$connection`, the package's connection — see one database
 * snapshot for the whole calculation.
 *
 * The calculation engine also names the stored component calculated: the
 * identity a stateful strategy keeps its state under (ADR-023).
 */
final readonly class CommissionCalculationContext
{
    public function __construct(
        public Program $program,
        public CommissionStrategyDefinition $definition,
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public string $connection,
        public ?string $planComponentId = null,
    ) {}
}
