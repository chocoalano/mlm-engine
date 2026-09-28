<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Support;

/**
 * The values a form currently holds, and the changes the server wants made
 * to them.
 *
 * Two things in one object because they are two halves of one question. A
 * reactive callback is asked "given what has been typed, what should this
 * form be now" — it has to read the sibling fields to answer, and it has to
 * be able to say "and clear that one" when its answer invalidates them.
 *
 * ## Reads
 *
 * `get()` takes a dotted path, so a relation group's `profile.bio` and a
 * repeater's `items.0.title` are reachable by the name the schema gave them.
 * A field the form does not hold reads as null rather than throwing: a
 * callback that runs before a dependency has been filled in is the normal
 * case, not an error.
 *
 * ## Writes
 *
 * `set()` and `forget()` do not edit the state in place and hope the renderer
 * notices. They record a *patch* — an explicit list of "this path becomes
 * this value" — which travels back to the browser beside the rebuilt schema
 * and overwrites what the user had.
 *
 * That distinction is the whole point. The renderer preserves what the user
 * typed when a schema is rebuilt, which is right: rebuilding describes what
 * the form should look like, not what it should hold. Without a separate
 * channel, a server that wanted to clear a now-invalid child field had no way
 * to say so — it could remove the value from the schema and watch the client
 * put it straight back. A patch is the server saying it, and it is the only
 * thing that wins over the client's own state.
 *
 * A patch can only ever be produced here, by a callback the schema declared.
 * Nothing in the request can name one — see `PanelFormStateController`.
 */
final class FormState
{
    /**
     * Paths the server has explicitly changed, in the order they were changed.
     *
     * @var array<string, mixed>
     */
    private array $patches = [];

    /**
     * @param  array<string, mixed>  $state  the values the form currently holds
     */
    public function __construct(private array $state = []) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public static function make(array $state = []): self
    {
        return new self($state);
    }

    /**
     * The value at a dotted path, or null when the form does not hold it.
     */
    public function get(string $path): mixed
    {
        $cursor = $this->state;

        // The flat key first: a relation group names its fields `profile.bio`
        // and the renderer keeps them flat, so the dotted name *is* the key.
        // Walking the segments first would miss it and answer null for a
        // field that is plainly there.
        if (array_key_exists($path, $cursor)) {
            return $cursor[$path];
        }

        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * Whether the form holds anything at all at this path, null included.
     *
     * Different from `get() !== null`: a select that has been deliberately
     * cleared holds null, and a select that was never rendered holds nothing.
     */
    public function has(string $path): bool
    {
        if (array_key_exists($path, $this->state)) {
            return true;
        }

        $cursor = $this->state;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }

    /**
     * Every value the form holds, as the browser sent them.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->state;
    }

    /**
     * Sets a field, and records that the server did so.
     *
     * The local state is updated too, so a callback that sets one field and
     * then reads it back — or a second callback running after this one — sees
     * the new value rather than the stale one.
     */
    public function set(string $path, mixed $value): self
    {
        $this->patches[$path] = $value;

        $this->writeLocal($path, $value);

        return $this;
    }

    /**
     * Clears a field.
     *
     * Null rather than absent, because those are the same thing to a form: a
     * control that is on screen always holds something, and "nothing" is what
     * an empty one holds. A patch that removed the key would leave the
     * renderer with a field bound to `undefined`.
     */
    public function forget(string $path): self
    {
        return $this->set($path, null);
    }

    /**
     * `forget()` under the name a schema author is more likely to reach for
     * when the reason is "this child is no longer valid for its parent".
     */
    public function reset(string $path): self
    {
        return $this->forget($path);
    }

    /**
     * The changes the server made, for the response to carry.
     *
     * Empty for the overwhelming majority of rebuilds, which is what keeps
     * the client's "server wins" rule cheap: with no patches there is nothing
     * to overwrite and the user's own values stand untouched.
     *
     * @return array<string, mixed>
     */
    public function patches(): array
    {
        return $this->patches;
    }

    public function hasPatches(): bool
    {
        return $this->patches !== [];
    }

    /**
     * Writes into the local copy, creating intermediate maps as needed.
     *
     * The flat key wins where one already exists, for the reason `get()`
     * prefers it: `profile.bio` is one key in the renderer's values, and
     * splitting it here would produce a second, nested copy that nothing
     * reads.
     */
    private function writeLocal(string $path, mixed $value): void
    {
        if (array_key_exists($path, $this->state) || ! str_contains($path, '.')) {
            $this->state[$path] = $value;

            return;
        }

        $segments = explode('.', $path);
        $last = array_pop($segments);

        $cursor = &$this->state;

        foreach ($segments as $segment) {
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $cursor[$last] = $value;
    }
}
