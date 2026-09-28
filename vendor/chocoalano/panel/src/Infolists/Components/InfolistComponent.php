<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Components;

use Illuminate\Database\Eloquent\Model;

/**
 * Anything an infolist can contain: an entry, or a layout holding entries.
 *
 * The shape mirrors the form schema on purpose. A reader who knows one knows
 * the other, and the two stay separable: an infolist is read-only, so it has
 * no validation, no dehydration, and no notion of a page it is hidden on.
 */
abstract class InfolistComponent
{
    /**
     * @return array<string, mixed>|null
     */
    abstract public function toArray(Model $record): ?array;

    /**
     * Every entry inside this component, however deeply nested.
     *
     * @return list<Entry>
     */
    abstract public function entries(): array;
}
