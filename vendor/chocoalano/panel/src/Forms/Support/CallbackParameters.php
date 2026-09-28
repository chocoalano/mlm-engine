<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Support;

use Closure;
use ReflectionFunction;
use ReflectionNamedType;

/**
 * Calls a schema callback with whatever it asked for.
 *
 * The form callbacks predate `FormState`. They were positional —
 * `fn ($value, $previous, $record)` — and every schema in every application
 * built on this package is written that way. Adding a fourth positional
 * argument would have been silently wrong for any closure that declared three
 * and meant three, and adding a fifth later would be wrong again.
 *
 * So the new objects are asked for by type instead:
 *
 *     ->afterStateUpdated(fn (Set $set, Get $get) => ...)
 *     ->afterStateUpdated(fn ($value, Set $set) => ...)
 *     ->afterStateUpdated(fn ($value, $previous, $record) => ...)   // unchanged
 *
 * ## The compatibility rule
 *
 * A closure that mentions none of `FormState`, `Get`, or `Set` is called
 * exactly as it always was: the positional arguments, in the order they have
 * always been in. Nothing reflects on it and nothing about it changes.
 *
 * Only a closure that type-hints one of those three enters injection, and
 * none can exist in an application written before they did. That is what
 * makes this additive rather than a migration.
 *
 * ## Inside injection
 *
 * Each parameter is resolved by type first, then by name, then by taking the
 * next positional argument that nothing has claimed. So a closure can mix the
 * two styles freely and the positional arguments stay in their order.
 */
final class CallbackParameters
{
    /**
     * The types whose presence turns a callback into an injected one.
     *
     * @var list<class-string>
     */
    private const INJECTED_TYPES = [FormState::class, Get::class, Set::class];

    /**
     * @param  list<mixed>  $positional  the arguments this callback has always taken
     * @param  array<string, mixed>  $named  what may also be asked for by parameter name
     */
    public static function call(
        Closure $callback,
        array $positional,
        FormState $state,
        array $named = [],
    ): mixed {
        $reflection = new ReflectionFunction($callback);
        $parameters = $reflection->getParameters();

        if (! self::wantsInjection($parameters)) {
            return $callback(...$positional);
        }

        $byType = [
            FormState::class => $state,
            Get::class => new Get($state),
            Set::class => new Set($state),
        ];

        $queue = array_values($positional);
        $arguments = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : null;

            if ($name !== null && array_key_exists($name, $byType)) {
                $arguments[] = $byType[$name];

                continue;
            }

            if (array_key_exists($parameter->getName(), $named)) {
                $arguments[] = $named[$parameter->getName()];

                continue;
            }

            if ($queue !== []) {
                $arguments[] = array_shift($queue);

                continue;
            }

            $arguments[] = $parameter->isDefaultValueAvailable()
                ? $parameter->getDefaultValue()
                : null;
        }

        return $callback(...$arguments);
    }

    /**
     * Whether this callback asked for any of the injected types.
     *
     * @param  list<\ReflectionParameter>  $parameters
     */
    private static function wantsInjection(array $parameters): bool
    {
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && in_array($type->getName(), self::INJECTED_TYPES, true)) {
                return true;
            }
        }

        return false;
    }
}
