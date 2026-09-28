<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Rank;

/**
 * The rank a ladder evaluation arrived at: the qualifying rank with the
 * highest position.
 */
final readonly class SelectedRank
{
    public function __construct(
        public string $key,
        public string $name,
        public int $position,
    ) {}

    /**
     * @return array{key: string, name: string, position: int}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'position' => $this->position,
        ];
    }
}
