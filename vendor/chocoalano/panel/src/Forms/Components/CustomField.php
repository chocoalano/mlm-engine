<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use PandaPanel\Forms\Enums\FieldType;

/**
 * A field drawn by a component of this application's own.
 *
 * The component is a build-time registry key under
 * `resources/js/pages/Panels/{Panel}/Fields/`, never markup and never a path
 * — the same rule custom columns and widgets follow, and for the same reason:
 * a name resolved from data would be a way to render something the build
 * never saw.
 *
 * Everything else about it is an ordinary field. It validates with rules the
 * schema declares, dehydrates like any other, and can be hidden or made
 * conditional — the custom part is only how it draws.
 */
final class CustomField extends Field
{
    private string $component = '';

    /** @var array<string, mixed> */
    private array $config = [];

    public function type(): FieldType
    {
        return FieldType::Custom;
    }

    public function component(string $component): self
    {
        $this->component = $component;

        return $this;
    }

    /**
     * Extra settings the component reads. Scalars and arrays only, like every
     * other serialized value.
     *
     * @param  array<string, mixed>  $config
     */
    public function config(array $config): self
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'componentName' => $this->component,
            'config' => $this->config,
        ];
    }
}
