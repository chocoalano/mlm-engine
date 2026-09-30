<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Period;

/**
 * Where a commission period is (ADR-029). Each step is taken once, forward
 * only; a failed step leaves the period where it was.
 *
 * - `open`: created. It accepts business entries in its range until its
 *   calculation begins; calculating it again resumes it.
 * - `calculated`: every commission component has its run. Its range takes
 *   no new business entry; its commissions are reviewed.
 * - `finalized`: its approved commissions are held.
 * - `released`: its held commissions are available to post.
 */
enum CommissionPeriodStatus: string
{
    case Open = 'open';
    case Calculated = 'calculated';
    case Finalized = 'finalized';
    case Released = 'released';
}
