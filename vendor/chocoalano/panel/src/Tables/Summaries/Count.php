<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Summaries;

final class Count extends Summarizer
{
    public function aggregate(): string
    {
        return 'count';
    }

    /**
     * @param  list<mixed>  $values
     */
    protected function reduce(array $values): int
    {
        return count($values);
    }
}
