<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Support;

/**
 * Write access to the form's state, for a callback that has decided a sibling
 * field should change.
 *
 * A view over `FormState` like `Get` is, so what it writes is recorded as a
 * patch on the one state object the request is building — see `FormState` for
 * why a patch and not an edit.
 *
 * Invokable, so the common case reads as the sentence it is:
 *
 *     ->afterStateUpdated(fn (Set $set) => $set('position_id', null))
 */
final readonly class Set
{
    public function __construct(private FormState $state) {}

    public function __invoke(string $path, mixed $value): void
    {
        $this->state->set($path, $value);
    }

    public function set(string $path, mixed $value): void
    {
        $this->state->set($path, $value);
    }

    /**
     * Clears a field — see `FormState::forget()` for why that is null rather
     * than absent.
     */
    public function forget(string $path): void
    {
        $this->state->forget($path);
    }

    public function reset(string $path): void
    {
        $this->state->reset($path);
    }
}
