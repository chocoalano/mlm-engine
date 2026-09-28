<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Summaries;

final class Average extends Summarizer
{
    public function aggregate(): string
    {
        return 'avg';
    }

    /**
     * @param  list<mixed>  $values
     */
    protected function reduce(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        $numbers = array_map(static fn (mixed $value): float => (float) $value, $values);

        return array_sum($numbers) / count($numbers);
    }
}
