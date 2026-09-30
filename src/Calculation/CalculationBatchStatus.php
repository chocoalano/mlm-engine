<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Calculation;

/**
 * Where a hybrid calculation batch stands (ADR-028).
 *
 * - `open`: created, and not every component's run is linked yet — running,
 *   interrupted or failed alike. Calling the hybrid engine again with the
 *   same request resumes it. Its commissions are not posted.
 * - `completed`: every component's run is linked. Final.
 */
enum CalculationBatchStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
}
