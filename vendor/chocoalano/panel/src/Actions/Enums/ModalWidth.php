<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Enums;

/**
 * How wide a modal opens.
 *
 * A closed set, because each case maps to a literal Tailwind class on the
 * frontend. An arbitrary width would compile to nothing — the same reason
 * every other size and colour in this framework is an enum.
 */
enum ModalWidth: string
{
    case Small = 'sm';
    case Medium = 'md';
    case Large = 'lg';
    case ExtraLarge = 'xl';
    case TwoExtraLarge = '2xl';
    case FourExtraLarge = '4xl';
    case Screen = 'screen';
}
