<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Components;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Infolists\Enums\EntryType;

/**
 * An array or JSON column rendered as pairs.
 *
 * Flattened to strings here rather than in Vue, so a nested value cannot
 * arrive as an object the renderer has no branch for.
 */
final class KeyValueEntry extends Entry
{
    public function type(): EntryType
    {
        return EntryType::KeyValue;
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    public function toValue(Model $record): array
    {
        $value = $this->resolveValue($record);

        if (! is_array($value)) {
            return [];
        }

        $pairs = [];

        foreach ($value as $key => $item) {
            $pairs[] = [
                'key' => (string) $key,
                'value' => is_scalar($item) ? (string) $item : (string) json_encode($item),
            ];
        }

        return $pairs;
    }
}
