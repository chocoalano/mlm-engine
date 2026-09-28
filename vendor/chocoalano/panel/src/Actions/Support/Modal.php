<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Support;

use PandaPanel\Actions\Enums\ModalWidth;

/**
 * Everything about how an action's dialog behaves and nothing about what it
 * does.
 *
 * Held apart from the action so the two are not one class with thirty
 * setters, and so a modal's settings can be described once and reused. It
 * serializes to scalars like everything else that crosses: a width is an enum
 * case, custom content is a registry key, and no part of it is executable.
 */
final class Modal
{
    private ModalWidth $width = ModalWidth::Medium;

    private bool $slideOver = false;

    private bool $stickyHeader = false;

    private bool $stickyFooter = false;

    private bool $closeByClickingAway = true;

    private bool $closeByEscaping = true;

    private bool $autofocus = true;

    private ?string $heading = null;

    private ?string $description = null;

    private ?string $submitLabel = null;

    private ?string $cancelLabel = null;

    private bool $footerCancel = true;

    private ?string $component = null;

    /** @var array<string, mixed> */
    private array $config = [];

    public static function make(): self
    {
        return new self;
    }

    public function width(ModalWidth $width): self
    {
        $this->width = $width;

        return $this;
    }

    /**
     * Opens from the side rather than the centre.
     *
     * The same content either way — a slide-over is a placement, not a
     * different kind of dialog, so nothing inside it has to know.
     */
    public function slideOver(bool $slideOver = true): self
    {
        $this->slideOver = $slideOver;

        return $this;
    }

    public function stickyHeader(bool $sticky = true): self
    {
        $this->stickyHeader = $sticky;

        return $this;
    }

    public function stickyFooter(bool $sticky = true): self
    {
        $this->stickyFooter = $sticky;

        return $this;
    }

    /**
     * Whether a click outside closes it.
     *
     * Worth turning off for a long form, where losing what was typed to a
     * stray click is the failure people actually hit.
     */
    public function closeByClickingAway(bool $close = true): self
    {
        $this->closeByClickingAway = $close;

        return $this;
    }

    public function closeByEscaping(bool $close = true): self
    {
        $this->closeByEscaping = $close;

        return $this;
    }

    public function autofocus(bool $autofocus = true): self
    {
        $this->autofocus = $autofocus;

        return $this;
    }

    public function heading(string $heading): self
    {
        $this->heading = $heading;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function submitLabel(string $label): self
    {
        $this->submitLabel = $label;

        return $this;
    }

    public function cancelLabel(string $label): self
    {
        $this->cancelLabel = $label;

        return $this;
    }

    /**
     * Drops the cancel button, for a dialog whose only way out is a decision.
     */
    public function withoutCancel(bool $without = true): self
    {
        $this->footerCancel = ! $without;

        return $this;
    }

    /**
     * Content drawn by a component of this application's own.
     *
     * A build-time registry key under `resources/js/pages/Panels/{Panel}/
     * Modals/`, never markup — the same rule custom columns, fields, and
     * widgets follow. It renders above whatever else the modal holds, so a
     * form action can explain itself in its own words.
     *
     * @param  array<string, mixed>  $config
     */
    public function content(string $component, array $config = []): self
    {
        $this->component = $component;
        $this->config = $config;

        return $this;
    }

    public function getHeading(): ?string
    {
        return $this->heading;
    }

    public function getSubmitLabel(): ?string
    {
        return $this->submitLabel;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width->value,
            'slideOver' => $this->slideOver,
            'stickyHeader' => $this->stickyHeader,
            'stickyFooter' => $this->stickyFooter,
            'closeByClickingAway' => $this->closeByClickingAway,
            'closeByEscaping' => $this->closeByEscaping,
            'autofocus' => $this->autofocus,
            'heading' => $this->heading,
            'description' => $this->description,
            'submitLabel' => $this->submitLabel,
            'cancelLabel' => $this->cancelLabel,
            'cancel' => $this->footerCancel,
            'componentName' => $this->component,
            'config' => $this->config,
        ];
    }
}
