<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Components;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Infolists\Enums\EntryType;
use RuntimeException;

/**
 * A stored path shown as a picture.
 *
 * The URL is built on the server from the disk the entry declares, so the
 * browser never turns a disk name into a link. A disk with no public URL
 * answers null rather than a URL that 404s, and the renderer then shows the
 * placeholder — which is the honest outcome for a private disk.
 */
final class ImageEntry extends Entry
{
    private ?string $disk = null;

    private int $size = 96;

    private bool $circular = false;

    public function type(): EntryType
    {
        return EntryType::Image;
    }

    public function disk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    public function size(int $pixels): self
    {
        $this->size = $pixels;

        return $this;
    }

    public function circular(bool $circular = true): self
    {
        $this->circular = $circular;

        return $this;
    }

    public function toValue(Model $record): ?string
    {
        $value = $this->resolveValue($record);

        if (! is_string($value) || $value === '') {
            return null;
        }

        // An absolute URL is already an answer; only a stored path needs a
        // disk to resolve it.
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        if ($this->disk === null) {
            return $value;
        }

        try {
            return Storage::disk($this->disk)->url($value);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return ['size' => $this->size, 'circular' => $this->circular];
    }
}
