<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use PandaPanel\Forms\Enums\FieldType;

/**
 * Same semantics as a checkbox, rendered as a switch.
 */
final class Toggle extends Checkbox
{
    public function type(): FieldType
    {
        return FieldType::Toggle;
    }
}
