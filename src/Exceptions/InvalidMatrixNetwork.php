<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A matrix network that cannot be configured: its width is not a whole
 * number from 1 to 100, or the program already has one of another width —
 * a width never changes. Nothing is configured.
 */
final class InvalidMatrixNetwork extends DomainException
{
    public static function width(mixed $width): self
    {
        return new self('A matrix width is a PHP integer from 1 to 100; '.var_export($width, true).' given.');
    }

    public static function widthConflict(string $program, int $width, int $requested): self
    {
        return new self("Program [{$program}] already has a matrix network of width {$width}; it cannot become {$requested}: a matrix width is configured once and never changes.");
    }

    public static function missingProgram(string $program): self
    {
        return new self("Program [{$program}] does not exist; only a stored program has a matrix network.");
    }

    public static function outsideManager(): self
    {
        return new self('Matrix networks are written only by PandaBear\Mlm\Matrix\MatrixNetworkManager.');
    }
}
