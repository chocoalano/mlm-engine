<?php

declare(strict_types=1);

namespace PandaPanel\Widgets\Enums;

/**
 * How a chart draws its series.
 *
 * Closed, because each case maps to a shape the compiled-in renderer knows how
 * to draw. A variant nobody wrote a renderer for is not a chart the panel can
 * show, and saying so in the type is better than finding out at runtime.
 */
enum ChartVariant: string
{
    case Bar = 'bar';
    case Line = 'line';
    case Area = 'area';
    /** One bar per label, stacked into a single column each. */
    case Doughnut = 'doughnut';
}
