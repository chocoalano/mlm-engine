<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\ConflictingVolumeReplay;
use PandaBear\Mlm\Exceptions\InvalidVolumeReversal;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;

final class VolumeReversalTest extends DatabaseTestCase
{
    use RecordsVolume;

    public function test_a_reversal_is_a_second_entry_that_negates_the_first(): void
    {
        $member = Member::factory()->create();
        $original = $this->record($member, '20.125', 'order:ORD-1', type: 'retail');
        $before = $this->volumeRows()[$original->id];

        $reversal = $this->reverse($original, 'refund:RF-1', sourceType: 'refund', sourceId: 'RF-1', at: CarbonImmutable::parse('2026-06-20 09:30:00'));

        $this->assertSame(2, DB::table('mlm_volume_entries')->count());
        $this->assertSame($before, $this->volumeRows()[$original->id]);
        $this->assertSame('-20.125', $reversal->quantity->value());
        $this->assertTrue($reversal->quantity->equals($original->quantity->negate()));
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame($member->id, $reversal->member_id);
        $this->assertSame($member->program_id, $reversal->program_id);
        $this->assertSame('retail', $reversal->type);
        $this->assertSame('refund', $reversal->source_type);
        $this->assertSame('RF-1', $reversal->source_id);
        $this->assertSame('2026-06-20 09:30:00', $reversal->effective_at->format('Y-m-d H:i:s'));
        $this->assertTrue($reversal->reversalOf?->is($original));
        $this->assertTrue($original->fresh()?->reversal?->is($reversal));
    }

    public function test_a_reversal_takes_member_and_type_from_the_stored_entry(): void
    {
        $original = $this->record(Member::factory()->create(), '10', 'k');
        $stale = VolumeEntry::findOrFail($original->id);

        // Unsaved changes on the instance passed in.
        $stale->setRawAttributes([...$stale->getAttributes(), 'member_id' => Member::factory()->create()->id, 'type' => 'retail']);

        $reversal = $this->reverse($stale, 'r');

        $this->assertSame($original->member_id, $reversal->member_id);
        $this->assertSame('sales', $reversal->type);
    }

    public function test_an_exact_replay_of_a_reversal_returns_it(): void
    {
        $original = $this->record(Member::factory()->create(), '10', 'k');

        $reversal = $this->reverse($original, 'refund:RF-1');
        $replay = $this->reverse($original, 'refund:RF-1');

        $this->assertTrue($replay->is($reversal));
        $this->assertSame(2, DB::table('mlm_volume_entries')->count());
    }

    public function test_a_conflicting_replay_of_a_reversal_is_refused(): void
    {
        $original = $this->record(Member::factory()->create(), '10', 'k');
        $this->reverse($original, 'refund:RF-1', sourceId: 'RF-1');

        $this->expectException(ConflictingVolumeReplay::class);
        $this->expectExceptionMessage('differs in source_id');

        $this->reverse($original, 'refund:RF-1', sourceId: 'RF-2');
    }

    public function test_an_entry_is_reversed_only_once(): void
    {
        $original = $this->record(Member::factory()->create(), '10', 'k');
        $reversal = $this->reverse($original, 'refund:RF-1');
        $before = $this->volumeRows();

        try {
            $this->reverse($original, 'cancellation:CN-1', sourceType: 'cancellation', sourceId: 'CN-1');
            $this->fail('An entry was reversed twice.');
        } catch (InvalidVolumeReversal $exception) {
            $this->assertStringContainsString("already reversed by entry [{$reversal->id}]", $exception->getMessage());
            $this->assertSame($before, $this->volumeRows());
        }
    }

    public function test_a_reversal_cannot_itself_be_reversed(): void
    {
        $reversal = $this->reverse($this->record(Member::factory()->create(), '10', 'k'), 'r');
        $before = $this->volumeRows();

        try {
            $this->reverse($reversal, 'r2');
            $this->fail('A reversal was reversed.');
        } catch (InvalidVolumeReversal $exception) {
            $this->assertStringContainsString('is itself a reversal', $exception->getMessage());
            $this->assertSame($before, $this->volumeRows());
        }
    }

    public function test_losing_a_race_to_another_reversal_of_the_same_entry_is_refused(): void
    {
        $original = $this->record(Member::factory()->create(), '10', 'k');
        $competed = false;

        // Race simulation: once the recorder has checked that the entry is
        // not yet reversed, "another process" reverses it under its own key.
        DB::listen(static function (QueryExecuted $query) use (&$competed, $original): void {
            if ($competed || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '"reversal_of_id" = ?')) {
                return;
            }

            $competed = true;

            DB::table('mlm_volume_entries')->insert([
                'id' => (new VolumeEntry)->newUniqueId(),
                'program_id' => $original->program_id,
                'member_id' => $original->member_id,
                'type' => 'sales',
                'quantity_millionths' => -10_000_000,
                'source_type' => 'refund',
                'source_id' => 'RF-OTHER',
                'idempotency_key' => 'refund:RF-OTHER',
                'effective_at' => '2026-06-15 12:00:00',
                'reversal_of_id' => $original->id,
            ]);
        });

        $this->expectException(InvalidVolumeReversal::class);
        $this->expectExceptionMessage('already reversed');

        $this->reverse($original, 'refund:RF-MINE');
    }
}
