<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Support;

/**
 * Read-only access to what the form currently holds.
 *
 * A view over `FormState`, not a copy of it: both point at the same values,
 * so a `Set` earlier in the same callback is visible through a `Get` later in
 * it.
 *
 * It exists so a callback can say in its signature what it does. A closure
 * that takes only a `Get` cannot change the form, and that is worth being
 * able to see without reading the body.
 */
final readonly class Get
{
    public function __construct(private FormState $state) {}

    /**
     * The value at a dotted path, or null when the form does not hold it.
     */
    public function __invoke(string $path): mixed
    {
        return $this->state->get($path);
    }

    public function get(string $path): mixed
    {
        return $this->state->get($path);
    }

    public function has(string $path): bool
    {
        return $this->state->has($path);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->state->all();
    }
}
