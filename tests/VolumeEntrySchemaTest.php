<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;

/**
 * Entries cannot be written around the recorder through the model, and the
 * database backs the history's local invariants — one entry per key and
 * program, one reversal per entry, foreign keys. None of that makes a raw
 * write a supported way to keep the history consistent.
 */
final class VolumeEntrySchemaTest extends DatabaseTestCase
{
    use RecordsVolume;

    public function test_an_entry_cannot_be_created_through_the_model(): void
    {
        $member = Member::factory()->create();

        $this->expectException(InvalidVolumeEntry::class);

        (new VolumeEntry)->forceFill([
            'program_id' => $member->program_id,
            'member_id' => $member->id,
            'type' => 'sales',
            'quantity_millionths' => 1_000_000,
            'source_type' => 'order',
            'source_id' => 'ORD-1',
            'idempotency_key' => 'k',
            'effective_at' => now(),
        ])->save();
    }

    public function test_an_entry_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new VolumeEntry(['quantity_millionths' => 1]);
    }

    public function test_an_entry_cannot_be_changed_or_deleted_through_the_model(): void
    {
        $entry = $this->record(Member::factory()->create(), '10', 'k');
        $before = $this->volumeRows();

        foreach ([
            'quantity' => fn () => $entry->forceFill(['quantity_millionths' => 99])->save(),
            'member' => fn () => $entry->forceFill(['member_id' => Member::factory()->create()->id])->save(),
            'type' => fn () => $entry->forceFill(['type' => 'retail'])->save(),
            'delete' => fn () => $entry->delete(),
        ] as $change => $write) {
            try {
                $write();
                $this->fail("An entry {$change} change went through the model.");
            } catch (InvalidVolumeEntry) {
                $entry->refresh();
                $this->assertSame($before, $this->volumeRows(), "The {$change} change reached the database.");
            }
        }
    }

    public function test_a_member_with_volume_cannot_be_deleted(): void
    {
        $member = Member::factory()->create();
        $this->record($member, '10', 'k');

        $this->expectException(QueryException::class);

        $member->delete();
    }

    public function test_a_program_with_volume_cannot_be_deleted(): void
    {
        $member = Member::factory()->create();
        $this->record($member, '10', 'k');

        // Remove what else restricts the program, so only volume holds it.
        DB::table('mlm_members')->where('id', '!=', $member->id)->delete();

        $this->expectException(QueryException::class);

        $member->program->delete();
    }

    public function test_the_database_allows_one_entry_per_key_in_a_program(): void
    {
        $entry = $this->record(Member::factory()->create(), '10', 'k');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('mlm_volume_entries')->insert([...$this->volumeRows()[$entry->id], 'id' => (new VolumeEntry)->newUniqueId()]);
    }

    public function test_the_database_allows_one_reversal_per_entry(): void
    {
        $original = $this->record(Member::factory()->create(), '10', 'k');
        $reversal = $this->reverse($original, 'r');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('mlm_volume_entries')->insert([
            ...$this->volumeRows()[$reversal->id],
            'id' => (new VolumeEntry)->newUniqueId(),
            'idempotency_key' => 'another-key',
        ]);
    }

    public function test_the_database_refuses_a_reversal_of_an_entry_that_does_not_exist(): void
    {
        $entry = $this->record(Member::factory()->create(), '10', 'k');

        $this->expectException(QueryException::class);

        DB::table('mlm_volume_entries')->insert([
            ...$this->volumeRows()[$entry->id],
            'id' => (new VolumeEntry)->newUniqueId(),
            'idempotency_key' => 'orphan',
            'reversal_of_id' => (new VolumeEntry)->newUniqueId(),
        ]);
    }
}
