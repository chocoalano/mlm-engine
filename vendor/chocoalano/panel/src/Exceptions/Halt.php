<?php

declare(strict_types=1);

namespace PandaPanel\Exceptions;

use RuntimeException;

/**
 * Stops a page's lifecycle without failing the request.
 *
 * Thrown by `$this->halt()` from any hook. The page catches it and returns
 * the user where they came from, having written nothing. It is deliberately
 * not an HTTP exception: a halt is a decision the page made, not an error,
 * and it must not surface as a 500 or leak a stack trace.
 *
 * A hook that wants to explain itself flashes a notification before halting.
 */
final class Halt extends RuntimeException
{
    public static function make(): self
    {
        return new self('The panel page halted its lifecycle.');
    }
}
