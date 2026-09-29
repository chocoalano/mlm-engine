<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Calculation;

use PandaBear\Mlm\Commission\CommissionComponentParameters;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;

/**
 * @internal
 *
 * A calculation's stored inputs, as the engine read and checked them.
 */
final readonly class CalculationRequest
{
    public function __construct(
        public PlanComponent $component,
        public PlanVersion $version,
        public Program $program,
        public CommissionComponentParameters $parameters,
        public LedgerAccount $source,
        public string $where,
    ) {}
}
