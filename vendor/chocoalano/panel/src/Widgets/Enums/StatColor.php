<?php

declare(strict_types=1);

namespace PandaPanel\Widgets\Enums;

/**
 * Semantic stat colours.
 *
 * A closed set because the frontend maps each case to a literal Tailwind
 * class; a free-form colour name would compile to nothing.
 */
enum StatColor: string
{
    case Default = 'default';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
    case Info = 'info';
}
