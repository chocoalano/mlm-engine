<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use PandaPanel\Core\Panel;

/**
 * Request-scoped panel state.
 *
 * Bound as a container singleton, so it lives exactly as long as the request
 * or the test case. Nothing here is static, which is what keeps panel state
 * from leaking between requests and between tests.
 *
 * The generic context bag exists so tenancy or module scope can be attached
 * later without changing this class's shape.
 */
final class PanelContext
{
    private ?Panel $panel = null;

    /** @var array<string, mixed> */
    private array $context = [];

    public function setPanel(?Panel $panel): void
    {
        $this->panel = $panel;
    }

    public function panel(): ?Panel
    {
        return $this->panel;
    }

    public function hasPanel(): bool
    {
        return $this->panel instanceof Panel;
    }

    public function set(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    public function forget(): void
    {
        $this->panel = null;
        $this->context = [];
    }
}
