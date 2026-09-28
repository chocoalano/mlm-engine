<?php

declare(strict_types=1);

namespace PandaPanel\Widgets;

use PandaPanel\Widgets\Enums\WidgetType;
use RuntimeException;

/**
 * A widget rendered by an application-supplied Vue component.
 *
 * The component name comes from this class, never from a request. The
 * frontend resolves it through a build-time glob, so a name that was not
 * compiled in cannot be reached at all.
 */
abstract class CustomWidget extends Widget
{
    /**
     * A path under `resources/js/pages/`, for example
     * `Panels/Admin/Widgets/ServerHealth`.
     */
    protected static string $component = '';

    public static function type(): WidgetType
    {
        return WidgetType::Custom;
    }

    public static function component(): string
    {
        if (static::$component === '') {
            throw new RuntimeException(
                sprintf('The custom widget [%s] must declare a $component.', static::class),
            );
        }

        return static::$component;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDefinition(): array
    {
        return [
            ...parent::toDefinition(),
            'component' => static::component(),
        ];
    }
}
