<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use PandaPanel\Forms\Enums\FieldType;

class TextInput extends Field
{
    protected bool $email = false;

    protected ?int $maxLength = 255;

    protected ?int $minLength = null;

    public function type(): FieldType
    {
        return FieldType::Text;
    }

    public function email(bool $email = true): static
    {
        $this->email = $email;

        return $this;
    }

    public function maxLength(?int $length): static
    {
        $this->maxLength = $length;

        return $this;
    }

    public function minLength(?int $length): static
    {
        $this->minLength = $length;

        return $this;
    }

    /**
     * @return list<string>
     */
    protected function typeRules(): array
    {
        $rules = ['string'];

        if ($this->email) {
            $rules[] = 'email';
        }

        if ($this->maxLength !== null) {
            $rules[] = 'max:'.$this->maxLength;
        }

        if ($this->minLength !== null) {
            $rules[] = 'min:'.$this->minLength;
        }

        return $rules;
    }

    protected function castForForm(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * `maxLength` also reaches the browser so the control can stop typing at
     * the limit. It is a convenience, not the check: the rule above is.
     *
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'inputType' => $this->email ? 'email' : 'text',
            'maxLength' => $this->maxLength,
        ];
    }
}
