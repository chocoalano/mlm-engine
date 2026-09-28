<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use PandaPanel\Forms\Enums\FieldType;

/**
 * A list of short strings the user types.
 *
 * Unlike a checkbox list there is no whitelist — the point is that the values
 * are not known in advance. What is bounded instead is how many and how long,
 * because an unbounded array from a form is an unbounded write.
 *
 * Stored as an array. A model that keeps them in a single column should cast
 * the attribute to `array`; storing them joined is a `dehydrateStateUsing()`
 * away and stays the schema's decision rather than this field's.
 */
final class TagsInput extends Field
{
    /** @var list<string> */
    private array $suggestions = [];

    private ?int $maxTags = null;

    private int $maxLength = 50;

    private ?string $separator = null;

    public function type(): FieldType
    {
        return FieldType::TagsInput;
    }

    /**
     * @param  list<string>  $suggestions
     */
    public function suggestions(array $suggestions): self
    {
        $this->suggestions = $suggestions;

        return $this;
    }

    public function maxTags(int $max): self
    {
        $this->maxTags = max(1, $max);

        return $this;
    }

    public function maxLength(int $length): self
    {
        $this->maxLength = max(1, $length);

        return $this;
    }

    /**
     * Splits one typed string into several tags, for pasting a list.
     *
     * An empty separator would split every character into its own tag, so it
     * is refused rather than accepted and regretted.
     */
    public function separator(string $separator): self
    {
        $this->separator = $separator === '' ? null : $separator;

        return $this;
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        $rules = ['array'];

        if ($this->maxTags !== null) {
            $rules[] = 'max:'.$this->maxTags;
        }

        return $rules;
    }

    /**
     * @return list<mixed>
     */
    public function elementRules(): array
    {
        return ['string', 'max:'.$this->maxLength];
    }

    /**
     * @return list<string>
     */
    protected function castForForm(mixed $value): array
    {
        $separator = $this->separator;

        if (is_string($value) && $separator !== null && $separator !== '') {
            $value = explode($separator, $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(strval(...), $value),
            static fn (string $tag): bool => $tag !== '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'suggestions' => $this->suggestions,
            'maxTags' => $this->maxTags,
            'maxLength' => $this->maxLength,
            'separator' => $this->separator,
        ];
    }
}
