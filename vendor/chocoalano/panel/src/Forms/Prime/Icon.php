<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Prime;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;
use PandaPanel\Tables\Enums\BadgeColor;

/**
 * An icon on its own, as content.
 *
 * The name is a registry key like every other icon in the panel, so a schema
 * cannot ask the browser for one the build did not compile in.
 */
final class Icon extends FormComponent
{
    private ?BadgeColor $color = null;

    private ?string $label = null;

    public function __construct(private readonly string $icon) {}

    public static function make(string $icon): self
    {
        return new self($icon);
    }

    public function color(BadgeColor $color): self
    {
        $this->color = $color;

        return $this;
    }

    /**
     * The accessible name. An icon with none reads as nothing at all.
     */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
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
            'component' => 'prime-icon',
            'icon' => $this->icon,
            'color' => $this->color?->value,
            'label' => $this->label,
        ];
    }
}
