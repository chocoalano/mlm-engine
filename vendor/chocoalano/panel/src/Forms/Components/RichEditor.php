<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Enums\FieldType;
use PandaPanel\Support\SafeUrl;

/**
 * Formatted text, stored as HTML.
 *
 * HTML from a form is the one field value that is dangerous by default: it is
 * written by a user and later rendered as markup, which is the definition of
 * stored XSS. So the value is sanitized on the way in, against an allowlist
 * of tags and attributes this field declares — not on the way out, where a
 * single unescaped render would undo it, and not by trusting the editor,
 * which is a control the browser can be told to skip.
 *
 * The allowlist is deliberately small. Widening it is a decision a schema
 * makes explicitly with `allowedTags()`, and every tag added is a tag
 * somebody has to be sure about.
 */
final class RichEditor extends Field
{
    /**
     * Tags that survive sanitizing.
     *
     * No `script`, `style`, `iframe`, `object`, `embed`, or `form` — each of
     * those turns stored text into behaviour.
     *
     * @var list<string>
     */
    private array $allowedTags = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li', 'blockquote', 'code', 'pre',
        'h2', 'h3', 'h4', 'a', 'hr',
    ];

    /** @var list<string> */
    private array $toolbar = [
        'bold', 'italic', 'strike', 'link',
        'h2', 'h3', 'bulletList', 'orderedList', 'blockquote', 'undo', 'redo',
    ];

    private ?int $maxLength = null;

    public function type(): FieldType
    {
        return FieldType::RichEditor;
    }

    /**
     * @param  list<string>  $tags
     */
    public function allowedTags(array $tags): self
    {
        $this->allowedTags = $tags;

        return $this;
    }

    /**
     * @param  list<string>  $buttons
     */
    public function toolbar(array $buttons): self
    {
        $this->toolbar = $buttons;

        return $this;
    }

    public function maxLength(int $length): self
    {
        $this->maxLength = max(1, $length);

        return $this;
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        $rules = ['string'];

        if ($this->maxLength !== null) {
            $rules[] = 'max:'.$this->maxLength;
        }

        return $rules;
    }

    /**
     * Sanitizing happens here — on the way to the record — so the stored
     * value is already safe and every later read of it is too.
     */
    public function mutate(mixed $value, ?Model $record): mixed
    {
        $sanitized = is_string($value) ? $this->sanitize($value) : $value;

        return parent::mutate($sanitized, $record);
    }

    /**
     * Strips everything outside the allowlist, then removes the attributes
     * that carry behaviour rather than presentation.
     */
    public function sanitize(string $html): string
    {
        $allowed = implode('', array_map(
            static fn (string $tag): string => '<'.$tag.'>',
            $this->allowedTags,
        ));

        $stripped = strip_tags($html, $allowed);

        // `on*` handlers, and any `href`/`src` whose decoded value is not a
        // safe navigation target. `strip_tags` keeps attributes, so it is not
        // enough by itself — this is the half that matters.
        $stripped = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $stripped) ?? '';
        $stripped = preg_replace_callback(
            '/\s+(href|src)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            static function (array $matches): string {
                $raw = $matches[2];
                $value = trim($raw, "\"'");

                return SafeUrl::isAllowed($value) ? $matches[0] : '';
            },
            $stripped,
        ) ?? '';

        return $stripped;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'toolbar' => $this->toolbar,
            'maxLength' => $this->maxLength,
        ];
    }
}
