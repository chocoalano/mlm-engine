<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\PlanVersion;
use Throwable;

/**
 * A plan version's definition — its components and their rules — is not
 * acceptable: refused as input, or found invalid when the version is
 * validated. The message names the version, component, rule and metric
 * concerned, never any parameter value.
 */
final class InvalidPlanDefinition extends DomainException
{
    public static function input(string $field, string $reason): self
    {
        return new self("Invalid plan definition {$field}: {$reason}");
    }

    public static function duplicateComponent(PlanVersion $version, string $key): self
    {
        return new self(sprintf(
            'Plan version [%s] (version %d) already has a component "%s"; a component key is used once per version.',
            $version->getKey(),
            $version->version,
            $key,
        ));
    }

    public static function duplicateRule(string $component, string $key): self
    {
        return new self("Component \"{$component}\" already has a rule \"{$key}\"; a rule key is used once per component.");
    }

    /**
     * The definition of `$version` fails at `$where` — "component "gold",
     * rule "entry"" — for `$reason`.
     */
    public static function inVersion(PlanVersion $version, string $where, string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf(
            'Plan version [%s] (version %d) has an invalid definition: %s: %s',
            $version->getKey(),
            $version->version,
            $where,
            $reason,
        ), previous: $previous);
    }

    /**
     * Stored definition data that cannot be read as written — only raw
     * writes produce it.
     */
    public static function unreadable(string $what, string $reason, ?Throwable $previous = null): self
    {
        return new self("The stored {$what} cannot be read: {$reason}", previous: $previous);
    }

    /**
     * @param  class-string  $model
     */
    public static function outsideEditor(string $model): self
    {
        return new self(sprintf(
            '%s is read-only through Eloquent; change a plan definition through %s.',
            $model,
            'PandaBear\Mlm\Planning\PlanDefinitionEditor',
        ));
    }
}
