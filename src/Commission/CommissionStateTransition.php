<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use Illuminate\Database\Connection;
use PandaBear\Mlm\Models\CalculationRun;

/**
 * The state a stateful strategy's run moves forward, planned from what its
 * calculation read. The calculation engine applies it once, after the run
 * and its commissions are stored, inside their transaction: if it throws,
 * nothing of the run is kept.
 *
 * It writes only its own strategy's state, and checks that what it read is
 * still what is stored rather than overwrite anything newer.
 */
interface CommissionStateTransition
{
    public function apply(Connection $connection, CalculationRun $run): void;
}
