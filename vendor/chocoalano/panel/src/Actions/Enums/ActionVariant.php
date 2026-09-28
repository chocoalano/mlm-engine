<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Enums;

/**
 * Button appearance. A closed set because the frontend maps each case to a
 * shadcn button variant; a free-form string would render unstyled.
 */
enum ActionVariant: string
{
    case Default = 'default';
    case Secondary = 'secondary';
    case Outline = 'outline';
    case Ghost = 'ghost';
    case Destructive = 'destructive';
}
