<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A binary pairing run that would not continue its component's state
 * exactly where it stands (ADR-023): a range already calculated, or one
 * leaving a gap. Each component's runs are contiguous. Nothing is written.
 */
final class InvalidBinaryPairingRange extends DomainException
{
    public static function stale(string $component, string $from, string $until, string $through): self
    {
        return new self("Binary pairing component [{$component}] has already calculated through {$through}; [{$from}, {$until}) overlaps it. Its next run starts at {$through}.");
    }

    public static function gap(string $component, string $from, string $until, string $through): self
    {
        return new self("Binary pairing component [{$component}] has calculated through {$through}; [{$from}, {$until}) would leave a gap. Its next run starts at {$through}.");
    }

    public static function withoutComponent(string $strategy): self
    {
        return new self("\"{$strategy}\" keeps its state per plan component: its calculation context must name the component, as the calculation engine's does.");
    }
}
