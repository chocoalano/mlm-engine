<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Prime;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;
use PandaPanel\Tables\Enums\BadgeColor;

/**
 * A line of text in a schema.
 *
 * One of the "prime" components: content with no value, no name, and nothing
 * to persist. They exist because a schema is not only a set of inputs — a
 * form often has to *say* something, and saying it with a disabled field
 * would put a name and a validation rule on a sentence.
 *
 * The text may be resolved from the record, so it can state something about
 * what is being edited.
 */
final class Text extends FormComponent
{
    private ?BadgeColor $color = null;

    private ?string $icon = null;

    private bool $small = false;

    /** @param  string|Closure(?Model): string  $content */
    public function __construct(private readonly string|Closure $content) {}

    /**
     * @param  string|Closure(?Model): string  $content
     */
    public static function make(string|Closure $content): self
    {
        return new self($content);
    }

    public function color(BadgeColor $color): self
    {
        $this->color = $color;

        return $this;
    }

    /**
     * An icon registry key, never a path.
     */
    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function small(bool $small = true): self
    {
        $this->small = $small;

        return $this;
    }

    /**
     * Content holds no fields, which is what makes it content.
     *
     * @return list<Field>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Model $record, string $page): array
    {
        return [
            'component' => 'prime-text',
            'content' => $this->content instanceof Closure
                ? ($this->content)($record)
                : $this->content,
            'color' => $this->color?->value,
            'icon' => $this->icon,
            'small' => $this->small,
        ];
    }
}
