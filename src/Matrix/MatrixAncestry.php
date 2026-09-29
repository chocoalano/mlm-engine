<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Matrix;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Support\EffectiveMoment;

/**
 * @internal
 *
 * The matrix line above the member of each of many volume entries, as it
 * stood when each entry took effect — the same reading as
 * `MatrixGenealogy::ancestorsAt()` per entry, but set-based: a chunk of
 * entries at a time, one read of their matrix paths and one of the
 * ancestors, never a query per entry. Matrix paths only: no sponsor,
 * generic placement or binary path is read. Read by the matrix strategies,
 * on the calculation's connection.
 */
final readonly class MatrixAncestry
{
    private const PATHS = 'mlm_genealogy_paths';

    private const CHUNK = 500;

    /**
     * Each entry with each of its matrix ancestors up to `$maxDepth`: the
     * entries in the order given, each one's ancestors nearest first. A
     * path counts only if it had taken effect by the entry's moment, and
     * only ancestors in the entry's program are read.
     *
     * @param  iterable<VolumeEntry>  $entries  each with its member loaded
     * @return iterable<array{VolumeEntry, MatrixRelative}>
     */
    public function ofEntries(string $connection, iterable $entries, int $maxDepth): iterable
    {
        $chunk = [];

        foreach ($entries as $entry) {
            $chunk[] = $entry;

            if (count($chunk) === self::CHUNK) {
                yield from $this->chunk($connection, $chunk, $maxDepth);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            yield from $this->chunk($connection, $chunk, $maxDepth);
        }
    }

    /**
     * @param  list<VolumeEntry>  $entries
     * @return iterable<array{VolumeEntry, MatrixRelative}>
     */
    private function chunk(string $connection, array $entries, int $maxDepth): iterable
    {
        $model = (new Member)->setConnection($connection);
        $db = $model->getConnection();

        $paths = [];

        foreach ($db->table(self::PATHS)
            ->where('tree_type', 'matrix')
            ->whereIn('descendant_id', array_values(array_unique(array_map(static fn (VolumeEntry $entry): string => $entry->member_id, $entries))))
            ->where('depth', '>', 0)
            ->where('depth', '<=', $maxDepth)
            ->orderBy('depth')
            ->orderBy('ancestor_id')
            ->get(['ancestor_id', 'descendant_id', 'depth', 'effective_from']) as $path) {
            $paths[(string) $path->descendant_id][] = [
                (string) $path->ancestor_id,
                (int) $path->depth,
                EffectiveMoment::of(CarbonImmutable::parse((string) $path->effective_from)),
            ];
        }

        if ($paths === []) {
            return;
        }

        $ancestors = $model->newQuery()
            ->whereKey(array_values(array_unique(array_merge(...array_map(static fn (array $lines): array => array_column($lines, 0), array_values($paths))))))
            ->get()
            ->keyBy('id');

        foreach ($entries as $entry) {
            $at = EffectiveMoment::of($entry->effective_at);

            foreach ($paths[$entry->member_id] ?? [] as [$ancestorId, $depth, $from]) {
                $ancestor = $ancestors->get($ancestorId);

                // A path holds from its effective_from on; and paths written
                // through the supported genealogies never cross programs.
                if ($from->lessThanOrEqualTo($at) && $ancestor?->program_id === $entry->program_id) {
                    yield [$entry, new MatrixRelative($ancestor, $depth)];
                }
            }
        }
    }
}
