<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Components;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Enums\CodeLanguage;
use PandaPanel\Infolists\Enums\EntryType;

/**
 * A value shown as preformatted text.
 *
 * An array or a JSON column is pretty-printed here rather than in Vue, so
 * what arrives is already the string that will be shown — the renderer never
 * has to decide what a nested structure looks like.
 */
final class CodeEntry extends Entry
{
    private CodeLanguage $language = CodeLanguage::Plain;

    private bool $copyable = false;

    public function type(): EntryType
    {
        return EntryType::Code;
    }

    public function language(CodeLanguage $language): self
    {
        $this->language = $language;

        return $this;
    }

    public function copyable(bool $copyable = true): self
    {
        $this->copyable = $copyable;

        return $this;
    }

    public function toValue(Model $record): ?string
    {
        $value = $this->resolveValue($record);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'language' => $this->language->value,
            'copyable' => $this->copyable,
        ];
    }
}
