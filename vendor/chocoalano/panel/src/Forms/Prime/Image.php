<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Prime;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;

/**
 * A picture in a schema.
 *
 * The URL is produced on the server — from a closure that may read the record
 * — so the browser never builds one from an identifier.
 */
final class Image extends FormComponent
{
    private ?string $alt = null;

    private ?int $width = null;

    private bool $rounded = false;

    /** @param  string|Closure(?Model): ?string  $url */
    public function __construct(private readonly string|Closure $url) {}

    /**
     * @param  string|Closure(?Model): ?string  $url
     */
    public static function make(string|Closure $url): self
    {
        return new self($url);
    }

    public function alt(string $alt): self
    {
        $this->alt = $alt;

        return $this;
    }

    public function width(int $pixels): self
    {
        $this->width = max(1, $pixels);

        return $this;
    }

    public function rounded(bool $rounded = true): self
    {
        $this->rounded = $rounded;

        return $this;
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Model $record, string $page): array
    {
        $url = $this->url instanceof Closure ? ($this->url)($record) : $this->url;

        return [
            'component' => 'prime-image',
            'url' => is_string($url) && $url !== '' ? $url : null,
            'alt' => $this->alt ?? '',
            'width' => $this->width,
            'rounded' => $this->rounded,
        ];
    }
}
