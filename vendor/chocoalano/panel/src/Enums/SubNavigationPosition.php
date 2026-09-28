<?php

declare(strict_types=1);

namespace PandaPanel\Enums;

/**
 * Where a record's sub-navigation sits relative to the page content.
 *
 * `Top` reads as tabs, `Start` and `End` as a rail beside the content. The
 * three values match Filament's, so the vocabulary transfers.
 */
enum SubNavigationPosition: string
{
    case Top = 'top';
    case Start = 'start';
    case End = 'end';
}
