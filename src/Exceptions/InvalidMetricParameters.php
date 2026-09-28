<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

final class InvalidMetricParameters extends DomainException
{
    /**
     * @param  list<string>  $unknown
     * @param  list<string>  $accepted
     */
    public static function unknown(string $metric, array $unknown, array $accepted): self
    {
        return new self(sprintf(
            'The metric "%s" does not accept %s; it accepts %s.',
            $metric,
            implode(', ', array_map(static fn (string $name): string => "\"{$name}\"", $unknown)),
            $accepted === [] ? 'no parameters' : implode(', ', array_map(static fn (string $name): string => "\"{$name}\"", $accepted)),
        ));
    }

    public static function missing(string $metric, string $parameter): self
    {
        return new self("The metric \"{$metric}\" requires the \"{$parameter}\" parameter.");
    }

    public static function invalid(string $metric, string $parameter, string $reason): self
    {
        return new self("The metric \"{$metric}\" received an invalid \"{$parameter}\": {$reason}");
    }

    public static function unnamed(int|string $name): self
    {
        return new self('Metric parameters are named: '.var_export($name, true).' is not a parameter name.');
    }
}
