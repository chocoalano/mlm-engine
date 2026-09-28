<?php

declare(strict_types=1);

namespace PandaPanel\Widgets;

use PandaPanel\Widgets\Enums\WidgetType;
use PandaPanel\Widgets\Support\Stat;

/**
 * A row of figures.
 *
 * Stats should come from aggregates. Hydrating a collection to count it is
 * the classic way a dashboard becomes the slowest page in the application.
 */
abstract class StatsWidget extends Widget
{
    public static function type(): WidgetType
    {
        return WidgetType::Stats;
    }

    /**
     * @return list<Stat>
     */
    abstract public function stats(): array;

    /**
     * @return array{stats: list<array<string, mixed>>}
     */
    public function data(): array
    {
        return [
            'stats' => array_map(
                static fn (Stat $stat): array => $stat->toArray(),
                $this->stats(),
            ),
        ];
    }
}
