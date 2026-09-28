<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\ConflictingVolumeReplay;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PHPUnit\Framework\Attributes\DataProvider;

final class VolumeIdempotencyTest extends DatabaseTestCase
{
    use RecordsVolume;

    public function test_an_exact_replay_returns_the_entry_already_recorded(): void
    {
        $member = Member::factory()->create();

        $first = $this->record($member, '25.5', 'order:ORD-1');
        $replay = $this->record($member, '25.50', 'order:ORD-1');

        $this->assertTrue($replay->is($first));
        $this->assertSame(1, DB::table('mlm_volume_entries')->count());
    }

    public function test_a_replay_naming_the_same_instant_in_another_timezone_is_the_same_request(): void
    {
        $member = Member::factory()->create();

        $first = $this->record($member, '1', 'k', at: CarbonImmutable::parse('2026-06-01 03:00:00', 'UTC'));
        $replay = $this->record($member, '1', 'k', at: CarbonImmutable::parse('2026-06-01 10:00:00', 'Asia/Jakarta'));

        $this->assertTrue($replay->is($first));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function conflictingReplays(): array
    {
        return [
            'another quantity' => [['quantity' => '26'], 'quantity'],
            'another type' => [['type' => 'retail'], 'type'],
            'another source type' => [['sourceType' => 'invoice'], 'source_type'],
            'another source id' => [['sourceId' => 'ORD-2'], 'source_id'],
            'another moment' => [['at' => CarbonImmutable::parse('2026-06-02 12:00:00')], 'effective_at'],
            'another member' => [['member' => 'other'], 'member'],
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('conflictingReplays')]
    public function test_a_replay_that_differs_in_a_material_field_is_refused(array $change, string $field): void
    {
        $member = Member::factory()->create();
        $other = Member::factory()->for($member->program)->create();
        $this->record($member, '25', 'order:ORD-1');
        $before = $this->volumeRows();

        $request = ['member' => $member, 'quantity' => '25', 'type' => 'sales', 'sourceType' => 'order', 'sourceId' => 'ORD-1', 'at' => null, ...$change];

        try {
            $this->record(
                $request['member'] === 'other' ? $other : $request['member'],
                $request['quantity'],
                'order:ORD-1',
                $request['type'],
                $request['sourceType'],
                $request['sourceId'],
                $request['at'],
            );
            $this->fail('A conflicting replay was accepted.');
        } catch (ConflictingVolumeReplay $exception) {
            $this->assertStringContainsString("differs in {$field}", $exception->getMessage());
            $this->assertSame($before, $this->volumeRows());
        }
    }

    public function test_the_same_key_is_independent_in_another_program(): void
    {
        $first = $this->record(Member::factory()->create(), '25', 'order:ORD-1');
        $second = $this->record(Member::factory()->create(), '25', 'order:ORD-1');

        $this->assertFalse($second->is($first));
        $this->assertSame(2, DB::table('mlm_volume_entries')->count());
    }

    public function test_a_key_already_used_for_a_record_cannot_be_reused_for_a_reversal(): void
    {
        $member = Member::factory()->create();
        $entry = $this->record($member, '25', 'order:ORD-1');

        $this->expectException(ConflictingVolumeReplay::class);

        $this->reverse($entry, 'order:ORD-1');
    }

    public function test_losing_a_race_to_an_identical_request_returns_the_winning_entry(): void
    {
        $member = Member::factory()->create();
        $winner = $this->competeAfterTheKeyCheck($member, quantity: 25_000_000);

        $entry = $this->record($member, '25', 'order:ORD-1');

        $this->assertSame($winner, $entry->id);
        $this->assertSame(1, DB::table('mlm_volume_entries')->count());
    }

    public function test_losing_a_race_to_a_different_request_is_refused(): void
    {
        $member = Member::factory()->create();
        $this->competeAfterTheKeyCheck($member, quantity: 99_000_000);

        try {
            $this->record($member, '25', 'order:ORD-1');
            $this->fail('A request that lost the race to a different one was accepted.');
        } catch (ConflictingVolumeReplay $exception) {
            $this->assertStringContainsString('differs in quantity', $exception->getMessage());
            $this->assertSame(1, DB::table('mlm_volume_entries')->count());
        }
    }

    /**
     * Race simulation: the moment the recorder's key check has found nothing,
     * "another process" records under the same key. SQLite cannot run two
     * sessions at once, so this is how the duplicate-key path is exercised.
     *
     * @return string the competing entry's id
     */
    private function competeAfterTheKeyCheck(Member $member, int $quantity): string
    {
        $id = (new VolumeEntry)->newUniqueId();
        $competed = false;

        DB::listen(static function (QueryExecuted $query) use (&$competed, $id, $member, $quantity): void {
            if ($competed || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'idempotency_key')) {
                return;
            }

            $competed = true;

            DB::table('mlm_volume_entries')->insert([
                'id' => $id,
                'program_id' => $member->program_id,
                'member_id' => $member->id,
                'type' => 'sales',
                'quantity_millionths' => $quantity,
                'source_type' => 'order',
                'source_id' => 'ORD-1',
                'idempotency_key' => 'order:ORD-1',
                'effective_at' => '2026-06-01 12:00:00',
            ]);
        });

        return $id;
    }
}
