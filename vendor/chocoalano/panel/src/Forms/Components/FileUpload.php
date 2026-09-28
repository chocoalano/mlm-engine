<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Forms\Enums\FieldType;

/**
 * A stored file, referenced by its path.
 *
 * The field never carries file contents. The browser posts the file to the
 * panel's upload endpoint, which stores it and answers with a path; the form
 * then submits that path like any other string. That split is what keeps the
 * form submit ordinary and puts every decision about *where* a file may land
 * on the server.
 *
 * Three things are declared here and enforced twice — once by the upload
 * endpoint and again when the form is submitted:
 *
 * - **the disk**, so a path cannot be made to point at another one;
 * - **the directory**, so a submitted path outside it is refused rather than
 *   attached to the record;
 * - **the accepted types and size**, because "the browser only offered
 *   images" is a control, not a constraint.
 */
final class FileUpload extends Field
{
    private string $disk = 'public';

    private string $directory = 'uploads';

    private bool $multiple = false;

    /** Kilobytes. */
    private int $maxSize = 5120;

    private ?int $maxFiles = null;

    /** @var list<string> */
    private array $acceptedTypes = [];

    private bool $image = false;

    public function type(): FieldType
    {
        return FieldType::FileUpload;
    }

    public function disk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Where files land, and the prefix a submitted path must start with.
     *
     * Normalized because the whole guarantee rests on a prefix comparison: a
     * directory with a trailing slash or a leading dot would compare against
     * paths that do not have one.
     */
    public function directory(string $directory): self
    {
        $this->directory = trim(str_replace('..', '', $directory), '/');

        return $this;
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function maxSize(int $kilobytes): self
    {
        $this->maxSize = max(1, $kilobytes);

        return $this;
    }

    public function maxFiles(int $max): self
    {
        $this->maxFiles = max(1, $max);

        return $this;
    }

    /**
     * @param  list<string>  $types  MIME types, such as `image/png`
     */
    public function acceptedTypes(array $types): self
    {
        $this->acceptedTypes = $types;

        return $this;
    }

    /**
     * Accepts the common image types and renders a preview.
     */
    public function image(bool $image = true): self
    {
        $this->image = $image;

        if ($image && $this->acceptedTypes === []) {
            $this->acceptedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
        }

        return $this;
    }

    public function getDisk(): string
    {
        return $this->disk;
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function getMaxSize(): int
    {
        return $this->maxSize;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    /**
     * @return list<string>
     */
    public function getAcceptedTypes(): array
    {
        return $this->acceptedTypes;
    }

    /**
     * Whether a submitted path is one this field could have produced.
     *
     * Checked on submit as well as on upload, because the two are separate
     * requests and only this one attaches the path to a record. A path
     * outside the directory, or naming a file that is not there, is refused.
     */
    public function accepts(string $path): bool
    {
        if ($path === '' || str_contains($path, '..')) {
            return false;
        }

        if (! str_starts_with($path, $this->directory.'/')) {
            return false;
        }

        return Storage::disk($this->disk)->exists($path);
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        if ($this->multiple) {
            $rules = ['array'];

            if ($this->maxFiles !== null) {
                $rules[] = 'max:'.$this->maxFiles;
            }

            return $rules;
        }

        return ['string'];
    }

    /**
     * @return list<mixed>
     */
    public function elementRules(): array
    {
        return $this->multiple ? ['string'] : [];
    }

    /**
     * Drops any path this field could not have produced.
     *
     * Silently, because a path that fails the check is not a value the user
     * typed — it is one that arrived from somewhere else.
     */
    public function mutate(mixed $value, ?Model $record): mixed
    {
        if ($this->multiple) {
            $paths = is_array($value) ? $value : [];

            $kept = array_values(array_filter(
                array_map(strval(...), $paths),
                fn (string $path): bool => $this->accepts($path),
            ));

            return parent::mutate($kept, $record);
        }

        $path = is_string($value) ? $value : '';

        return parent::mutate($this->accepts($path) ? $path : null, $record);
    }

    /**
     * @return string|list<string>|null
     */
    protected function castForForm(mixed $value): string|array|null
    {
        if ($this->multiple) {
            return is_array($value) ? array_values(array_map(strval(...), $value)) : [];
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'multiple' => $this->multiple,
            'maxSize' => $this->maxSize,
            'maxFiles' => $this->maxFiles,
            'acceptedTypes' => $this->acceptedTypes,
            'image' => $this->image,
            'previewBase' => $this->previewBase(),
        ];
    }

    /**
     * The URL a stored path is previewed at.
     *
     * Resolved on the server so the browser never builds one from a disk
     * name. A disk with no public URL — a private one, or a driver that does
     * not serve files — answers null rather than a link that 404s, and the
     * frontend then shows the file name instead of a broken image.
     */
    private function previewBase(): ?string
    {
        try {
            return rtrim(Storage::disk($this->disk)->url('/'), '/');
        } catch (\RuntimeException) {
            return null;
        }
    }
}
