<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Genealogy\SponsorRelative;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;

/**
 * Sponsor trees built the supported way — through SponsorGenealogy — and
 * read back as strings a failure message can show.
 */
trait BuildsSponsorTrees
{
    protected function genealogy(): SponsorGenealogy
    {
        return $this->app->make(SponsorGenealogy::class);
    }

    /**
     * @return array<string, Member> keyed by member code
     */
    protected function members(Program $program, string ...$codes): array
    {
        $members = [];

        foreach ($codes as $code) {
            $members[$code] = Member::factory()->for($program)->create(['member_code' => $code]);
        }

        return $members;
    }

    /**
     * @param  array<string, Member>  $members
     * @param  array<string, list<string>>  $tree  sponsor code => the codes it sponsors, in order
     */
    protected function sponsorTree(array $members, array $tree): void
    {
        foreach ($tree as $sponsor => $sponsored) {
            foreach ($sponsored as $code) {
                $this->genealogy()->assignSponsor($members[$code], $members[$sponsor]);
            }
        }
    }

    /**
     * Every sponsor edge and genealogy path, by member code.
     *
     * @return array{edges: list<string>, paths: list<string>}
     */
    protected function genealogyState(?string $connection = null): array
    {
        $codes = Member::query()->pluck('member_code', 'id');
        $db = DB::connection($connection);

        return [
            'edges' => $db->table('mlm_sponsor_edges')->get()
                ->map(static fn (object $edge): string => "{$codes[$edge->sponsor_id]} > {$codes[$edge->member_id]}")
                ->sort()->values()->all(),
            'paths' => $db->table('mlm_genealogy_paths')->get()
                ->map(static fn (object $path): string => "{$path->tree_type}: {$codes[$path->ancestor_id]} > {$codes[$path->descendant_id]} @{$path->depth}")
                ->sort()->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, SponsorRelative>  $relatives
     * @return list<string> "code@depth", in the order given
     */
    protected function relatives(Collection $relatives): array
    {
        return $relatives->map(static fn (SponsorRelative $relative): string => "{$relative->member->member_code}@{$relative->depth}")->all();
    }
}
