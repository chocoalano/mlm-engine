<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Enums\FieldType;
use PandaPanel\Forms\Support\Block;

/**
 * A repeater whose entries can each be a different shape.
 *
 * The value is a list of `{type, data}`. `type` names one of the declared
 * blocks and is the whole safety of the field: an entry naming a block the
 * builder never declared is dropped, not guessed at, and its data is
 * dehydrated by that block's own fields — so a key no block declared is
 * discarded exactly as it is on a plain form.
 *
 * Validation cannot be expressed as a flat rule set, because which rules
 * apply to entry three depends on what entry three says it is. So the
 * builder validates its entries itself, in `validateEntries()`, and reports
 * failures under the same dotted keys the frontend renders with.
 */
final class Builder extends Field
{
    /** @var list<Block> */
    private array $blocks = [];

    private ?int $minItems = null;

    private ?int $maxItems = null;

    private bool $reorderable = true;

    private bool $collapsible = true;

    private ?string $addLabel = null;

    public function type(): FieldType
    {
        return FieldType::Builder;
    }

    /**
     * @param  array<array-key, Block>  $blocks
     */
    public function blocks(array $blocks): self
    {
        $this->blocks = array_values($blocks);

        return $this;
    }

    public function minItems(int $min): self
    {
        $this->minItems = max(0, $min);

        return $this;
    }

    public function maxItems(int $max): self
    {
        $this->maxItems = max(1, $max);

        return $this;
    }

    public function reorderable(bool $reorderable = true): self
    {
        $this->reorderable = $reorderable;

        return $this;
    }

    public function collapsible(bool $collapsible = true): self
    {
        $this->collapsible = $collapsible;

        return $this;
    }

    public function addLabel(string $label): self
    {
        $this->addLabel = $label;

        return $this;
    }

    public function block(string $name): ?Block
    {
        foreach ($this->blocks as $block) {
            if ($block->getName() === $name) {
                return $block;
            }
        }

        return null;
    }

    /**
     * The blocks an entry may name.
     *
     * For working out where the fields inside them write — each block is its
     * own namespace, so two blocks may both hold a `text`.
     *
     * @return list<Block>
     */
    public function blocksForPaths(): array
    {
        return $this->blocks;
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [$this];
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        $rules = ['array'];

        if ($this->minItems !== null) {
            $rules[] = 'min:'.$this->minItems;
        }

        if ($this->maxItems !== null) {
            $rules[] = 'max:'.$this->maxItems;
        }

        return $rules;
    }

    /**
     * Validates each entry against the block it names.
     *
     * Not expressible as flat rules: which rules apply to entry three depends
     * on what entry three says it is. Errors come back under the dotted keys
     * the frontend rendered with, so they land on the field that produced
     * them.
     *
     * @return array<string, list<string>> errors keyed by path
     */
    public function validateEntries(mixed $value, ?Model $record = null): array
    {
        if (! is_array($value)) {
            return [];
        }

        $errors = [];

        foreach ($value as $index => $entry) {
            $path = $this->getName().'.'.$index;

            if (! is_array($entry) || ! is_string($entry['type'] ?? null)) {
                $errors[$path.'.type'] = [__('panda-panel::forms.builder.unknown_block')];

                continue;
            }

            $block = $this->block($entry['type']);

            if ($block === null) {
                $errors[$path.'.type'] = [__('panda-panel::forms.builder.unknown_block')];

                continue;
            }

            $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];

            $rules = [];

            foreach ($block->fields() as $field) {
                $rules[$field->getName()] = $field->validationRules($record);
            }

            $validator = validator($data, $rules);

            foreach ($validator->errors()->toArray() as $key => $messages) {
                $errors[$path.'.data.'.$key] = array_values($messages);
            }
        }

        return $errors;
    }

    /**
     * Each entry is dehydrated by the block it names, so an entry naming a
     * block that does not exist is dropped rather than written.
     */
    public function mutate(mixed $value, ?Model $record): mixed
    {
        if (! is_array($value)) {
            return parent::mutate([], $record);
        }

        $items = [];

        foreach ($value as $entry) {
            if (! is_array($entry) || ! is_string($entry['type'] ?? null)) {
                continue;
            }

            $block = $this->block($entry['type']);

            if ($block === null) {
                continue;
            }

            $items[] = [
                'type' => $block->getName(),
                'data' => $block->dehydrate(
                    is_array($entry['data'] ?? null) ? $entry['data'] : [],
                    $record,
                ),
            ];
        }

        return parent::mutate($items, $record);
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    protected function castForForm(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $entry) {
            if (! is_array($entry) || ! is_string($entry['type'] ?? null)) {
                continue;
            }

            if ($this->block($entry['type']) === null) {
                continue;
            }

            $items[] = [
                'type' => $entry['type'],
                'data' => is_array($entry['data'] ?? null) ? $entry['data'] : [],
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'blocks' => array_map(
                static fn (Block $block): array => $block->toArray(),
                $this->blocks,
            ),
            'minItems' => $this->minItems,
            'maxItems' => $this->maxItems,
            'reorderable' => $this->reorderable,
            'collapsible' => $this->collapsible,
            'addLabel' => $this->addLabel ?? 'Add block',
        ];
    }
}
