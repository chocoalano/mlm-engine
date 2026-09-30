<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Payout;

/**
 * Where a payout batch is (ADR-030): `open` while requests are added,
 * `sealed` once its membership is fixed, `processing` once every request
 * has started, `completed` once every request is settled or failed. An open
 * batch may be `cancelled`, which abandons the grouping only.
 */
enum PayoutBatchStatus: string
{
    case Open = 'open';
    case Sealed = 'sealed';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
