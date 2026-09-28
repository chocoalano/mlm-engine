<?php

declare(strict_types=1);

namespace PandaPanel\Search;

use PandaPanel\Support\SafeUrl;

/**
 * One hit, already reduced to what the palette draws.
 *
 * A result never carries a model or a query. By the time it exists the
 * record has been authorized, its title and details resolved, and its URL
 * generated on the server — so the frontend has nothing left to decide.
 */
final readonly class GlobalSearchResult
{
    /**
     * @param  array<string, string>  $details
     */
    public function __construct(
        public string $title,
        public string $url,
        public array $details = [],
    ) {}

    /**
     * @return array{title: string, url: string, details: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'url' => SafeUrl::sanitize($this->url) ?? '#',
            'details' => $this->details,
        ];
    }
}
