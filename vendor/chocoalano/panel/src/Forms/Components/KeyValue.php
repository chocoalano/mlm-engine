<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use PandaPanel\Forms\Enums\FieldType;

/**
 * Arbitrary name/value pairs.
 *
 * Both halves are free text, so what is bounded is the shape rather than the
 * content: how many pairs, and how long each half may be. A blank key is
 * dropped rather than stored, because a map with an empty key is a map with
 * one entry nobody can address.
 */
final class KeyValue extends Field
{
    private string $keyLabel = 'Key';

    private string $valueLabel = 'Value';

    private ?int $maxPairs = null;

    private bool $addable = true;

    private bool $deletable = true;

    private bool $editableKeys = true;

    public function type(): FieldType
    {
        return FieldType::KeyValue;
    }

    public function labels(string $key, string $value): self
    {
        $this->keyLabel = $key;
        $this->valueLabel = $value;

        return $this;
    }

    public function maxPairs(int $max): self
    {
        $this->maxPairs = max(1, $max);

        return $this;
    }

    public function addable(bool $addable = true): self
    {
        $this->addable = $addable;

        return $this;
    }

    public function deletable(bool $deletable = true): self
    {
        $this->deletable = $deletable;

        return $this;
    }

    /**
     * Locks the keys, for a fixed set of settings whose values may change but
     * whose names may not.
     */
    public function editableKeys(bool $editable = true): self
    {
        $this->editableKeys = $editable;

        return $this;
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        $rules = ['array'];

        if ($this->maxPairs !== null) {
            $rules[] = 'max:'.$this->maxPairs;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function castForForm(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($value)) {
            return [];
        }

        $pairs = [];

        foreach ($value as $key => $entry) {
            $key = (string) $key;

            // A map with an empty key has one entry nobody can address.
            if ($key === '' || ! is_scalar($entry)) {
                continue;
            }

            $pairs[$key] = (string) $entry;
        }

        return $pairs;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'keyLabel' => $this->keyLabel,
            'valueLabel' => $this->valueLabel,
            'maxPairs' => $this->maxPairs,
            'addable' => $this->addable,
            'deletable' => $this->deletable,
            'editableKeys' => $this->editableKeys,
        ];
    }
}
