<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use PandaPanel\Forms\Enums\FieldType;

class Checkbox extends Field
{
    protected mixed $default = false;

    public function type(): FieldType
    {
        return FieldType::Checkbox;
    }

    /**
     * A checkbox is always present in the payload as true or false, so
     * `required` would reject an unchecked box. `boolean` is the real rule.
     *
     * @return list<string>
     */
    protected function typeRules(): array
    {
        return ['boolean'];
    }

    protected function castForForm(mixed $value): bool
    {
        return (bool) $value;
    }
}
