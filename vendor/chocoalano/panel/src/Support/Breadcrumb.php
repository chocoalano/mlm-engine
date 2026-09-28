<?php

declare(strict_types=1);

namespace PandaPanel\Support;

/**
 * One crumb in a panel breadcrumb trail.
 *
 * Labels are plain text. Vue renders them as text, so never build a label
 * containing markup.
 */
final readonly class Breadcrumb
{
    public function __construct(
        public string $label,
        public ?string $href = null,
        public bool $current = false,
    ) {}

    public static function make(string $label): self
    {
        return new self($label);
    }

    public function url(?string $href): self
    {
        return new self($this->label, $href, $this->current);
    }

    public function current(bool $current = true): self
    {
        return new self($this->label, $this->href, $current);
    }

    /**
     * @return array{label: string, href: string|null, current: bool}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'href' => $this->href,
            'current' => $this->current,
        ];
    }
}
