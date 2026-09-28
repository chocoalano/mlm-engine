<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Summaries;

final class Sum extends Summarizer
{
    public function aggregate(): string
    {
        return 'sum';
    }

    /**
     * @param  list<mixed>  $values
     */
    protected function reduce(array $values): float|int
    {
        return array_sum(array_map(static fn (mixed $value): float => (float) $value, $values));
    }
}
