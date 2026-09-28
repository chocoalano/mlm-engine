<?php

declare(strict_types=1);

namespace PandaPanel\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The minimum a panel must expose to the route registrar, the middleware,
 * the navigation builder, and the Inertia sharing layer.
 *
 * Fluent builder methods use bare names (`id()`, `path()`); readers are
 * prefixed with `get` so a setter and a getter never collide on one name.
 */
interface PanelContract
{
    public function getId(): string;

    public function getPath(): string;

    public function getDomain(): ?string;

    /**
     * The full middleware stack for the panel route group, base plus auth.
     *
     * @return list<string>
     */
    public function getMiddleware(): array;

    public function isAccessibleTo(?Authenticatable $user): bool;
}
