<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Tables\Enums\ColumnType;

final class ImageColumn extends Column
{
    private bool $circular = false;

    private int $size = 32;

    /** @var (Closure(Model): string)|null */
    private ?Closure $fallbackUsing = null;

    protected bool $sortable = false;

    public function type(): ColumnType
    {
        return ColumnType::Image;
    }

    public function circular(bool $circular = true): self
    {
        $this->circular = $circular;

        return $this;
    }

    public function size(int $pixels): self
    {
        $this->size = $pixels;

        return $this;
    }

    /**
     * Produces the initials shown when there is no image. Runs backend-side;
     * only the resulting string is serialized.
     *
     * @param  Closure(Model): string  $callback
     */
    public function fallbackUsing(Closure $callback): self
    {
        $this->fallbackUsing = $callback;

        return $this;
    }

    /**
     * @return array{url: string|null, fallback: string, alt: string}
     */
    public function toCell(Model $record): array
    {
        $value = $this->resolveValue($record);

        $fallback = $this->fallbackUsing !== null
            ? ($this->fallbackUsing)($record)
            : Str::of((string) ($record->getAttribute('name') ?? ''))
                ->trim()
                ->explode(' ')
                ->take(2)
                ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
                ->implode('');

        return [
            'url' => is_string($value) && $value !== '' ? $value : null,
            'fallback' => $fallback,
            'alt' => (string) ($record->getAttribute('name') ?? $this->getLabel()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'circular' => $this->circular,
            'size' => $this->size,
        ];
    }
}
