<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Enums\FieldType;

/**
 * A password field.
 *
 * `optionalWhenFilled()` is the whole reason the dehydration hook exists: on
 * edit the field must be optional, still validated when the user types
 * something, and dropped entirely when they leave it blank so the stored
 * hash is not overwritten with an empty string.
 */
final class PasswordInput extends Field
{
    private bool $confirmed = false;

    private bool $revealable = true;

    public function type(): FieldType
    {
        return FieldType::Password;
    }

    /**
     * Adds Laravel's `confirmed` rule, which expects a
     * `{name}_confirmation` field.
     */
    public function confirmed(bool $confirmed = true): self
    {
        $this->confirmed = $confirmed;

        return $this;
    }

    public function revealable(bool $revealable = true): self
    {
        $this->revealable = $revealable;

        return $this;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    /**
     * Optional, but persisted only when the user actually entered something.
     */
    public function optionalWhenFilled(): self
    {
        return $this
            ->required(false)
            ->dehydrateWhen(static fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    /**
     * @return list<string>
     */
    protected function typeRules(): array
    {
        return $this->confirmed ? ['string', 'confirmed'] : ['string'];
    }

    /**
     * A password value is never sent back to the browser. Rendering the
     * stored hash into a form field would put it on screen and in the page
     * payload.
     */
    public function formValue(?Model $record): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'revealable' => $this->revealable,
            'confirmed' => $this->confirmed,
        ];
    }
}
