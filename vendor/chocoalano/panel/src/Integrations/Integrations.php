<?php

declare(strict_types=1);

namespace PandaPanel\Integrations;

/**
 * A resource's integration settings.
 *
 * Off unless a resource says otherwise. Integrations make the server issue
 * outbound HTTP on every write to a table, configured at runtime by whoever
 * can reach the screen — that is not something a resource should acquire by
 * being upgraded into, so the default is `false` and turning it on is a
 * deliberate line of code:
 *
 * ```php
 * public static function integrations(Integrations $integrations): Integrations
 * {
 *     return $integrations->isEnabled(true);
 * }
 * ```
 *
 * Enabling it registers the model observer and adds the resource's
 * Integrations page. It does not create any integrations: an enabled resource
 * with none configured behaves exactly as before, which is what makes turning
 * it on safe to do in advance of using it.
 */
final class Integrations
{
    private bool $enabled = false;

    /** @var list<Trigger>|null */
    private ?array $triggers = null;

    private int $timeout = 5;

    public static function make(): self
    {
        return new self;
    }

    /**
     * Turns integrations on or off for this resource.
     *
     * Reads as a question and works as a statement, which is deliberate: it
     * is the one line in a resource that decides whether any of this exists,
     * and it should be greppable as `isEnabled(true)`.
     */
    public function isEnabled(bool $enabled = true): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Narrows which of the six triggers this resource offers.
     *
     * All of them by default. A resource whose deletes are soft and whose
     * `deleting` event fires on every archive may want to say so here rather
     * than leaving somebody to discover it from the receiving end.
     *
     * @param  list<Trigger>  $triggers
     */
    public function triggers(array $triggers): self
    {
        $this->triggers = array_values($triggers);

        return $this;
    }

    /**
     * @return list<Trigger>
     */
    public function getTriggers(): array
    {
        return $this->triggers ?? Trigger::cases();
    }

    public function supports(Trigger $trigger): bool
    {
        return in_array($trigger, $this->getTriggers(), true);
    }

    /**
     * How long a `before` request may take before it is abandoned.
     *
     * Short on purpose. A `before` trigger runs inside the request that is
     * writing the record, so every second here is a second somebody is
     * watching a spinner; the write is never cancelled by a slow endpoint,
     * but it can certainly be delayed by one.
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = max(1, $seconds);

        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }
}
