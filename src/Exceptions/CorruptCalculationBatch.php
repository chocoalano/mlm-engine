<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A stored hybrid batch that no supported write produces — items that are
 * not exactly its plan version's commission components in their order, or
 * an item linked to a run that is not its component's run. Refused rather
 * than resumed, and never repaired: correct the rows.
 */
final class CorruptCalculationBatch extends DomainException
{
    public static function components(string $batch, string $reason): self
    {
        return new self("Calculation batch [{$batch}] does not hold its plan version's commission components: {$reason}.");
    }

    public static function run(string $batch, string $item, string $run, string $reason): self
    {
        return new self("Item [{$item}] of calculation batch [{$batch}] is linked to calculation run [{$run}], which {$reason}.");
    }
}
