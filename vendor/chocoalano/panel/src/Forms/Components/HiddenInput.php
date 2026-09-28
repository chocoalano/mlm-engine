<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use PandaPanel\Forms\Enums\FieldType;

/**
 * Carries a value through the form without showing it.
 *
 * Hidden is a rendering choice, not a trust boundary: the value still
 * arrives from the browser and is still validated by the rules declared
 * here.
 */
final class HiddenInput extends Field
{
    public function type(): FieldType
    {
        return FieldType::Hidden;
    }
}
