<?php

declare(strict_types=1);

namespace PandaPanel\Contracts;

use PandaPanel\Widgets\Enums\WidgetType;

/**
 * Implemented by `PandaPanel\Widgets\Widget`.
 */
interface WidgetContract
{
    public static function id(): string;

    public static function type(): WidgetType;

    /**
     * Checked before `toArray()` runs, so an unauthorized widget never
     * executes its queries.
     */
    public static function canView(): bool;

    /**
     * The serialized widget definition sent to Vue. Scalars, arrays, and
     * nulls only.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
