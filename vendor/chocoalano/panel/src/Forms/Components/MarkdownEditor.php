<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use PandaPanel\Forms\Enums\FieldType;

/**
 * Formatted text, stored as Markdown.
 *
 * Safer to store than HTML because it is inert until something renders it,
 * and whatever does the rendering is where escaping belongs. That is why this
 * field does not sanitize and `RichEditor` does: the danger is in the storage
 * format, not in the editor.
 */
final class MarkdownEditor extends Field
{
    /** @var list<string> */
    private array $toolbar = [
        'bold', 'italic', 'strike', 'link',
        'heading', 'bulletList', 'orderedList', 'blockquote', 'code', 'preview',
    ];

    private ?int $maxLength = null;

    private int $rows = 10;

    public function type(): FieldType
    {
        return FieldType::MarkdownEditor;
    }

    /**
     * @param  list<string>  $buttons
     */
    public function toolbar(array $buttons): self
    {
        $this->toolbar = $buttons;

        return $this;
    }

    public function maxLength(int $length): self
    {
        $this->maxLength = max(1, $length);

        return $this;
    }

    public function rows(int $rows): self
    {
        $this->rows = max(1, $rows);

        return $this;
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        $rules = ['string'];

        if ($this->maxLength !== null) {
            $rules[] = 'max:'.$this->maxLength;
        }

        return $rules;
    }

    protected function castForForm(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'toolbar' => $this->toolbar,
            'maxLength' => $this->maxLength,
            'rows' => $this->rows,
        ];
    }
}
