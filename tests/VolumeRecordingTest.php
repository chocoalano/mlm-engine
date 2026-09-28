<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PandaBear\Mlm\Volume\Quantity;
use PandaBear\Mlm\Volume\RecordVolume;
use PHPUnit\Framework\Attributes\DataProvider;
use TypeError;

final class VolumeRecordingTest extends DatabaseTestCase
{
    use RecordsVolume;

    public function test_it_records_one_immutable_entry_for_the_member(): void
    {
        $member = Member::factory()->create();
        $this->travelTo('2026-07-01 08:00:00');

        $entry = $this->recorder()->record(new RecordVolume(
            member: $member,
            type: 'retail',
            quantity: Quantity::of('25.125'),
            sourceType: 'order',
            sourceId: 'ORD-123',
            idempotencyKey: 'order:ORD-123:retail',
            effectiveAt: CarbonImmutable::parse('2026-06-30 23:59:59'),
        ));

        $this->assertTrue(Str::isUlid($entry->id));
        $this->assertTrue($entry->member->is($member));
        $this->assertTrue($entry->program->is($member->program));
        $this->assertSame('retail', $entry->type);
        $this->assertSame('25.125', $entry->quantity->value());
        $this->assertSame('order', $entry->source_type);
        $this->assertSame('ORD-123', $entry->source_id);
        $this->assertSame('order:ORD-123:retail', $entry->idempotency_key);
        $this->assertNull($entry->reversal_of_id);
        $this->assertInstanceOf(CarbonImmutable::class, $entry->effective_at);
        $this->assertSame('2026-06-30 23:59:59', $entry->effective_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-01 08:00:00', $entry->created_at?->format('Y-m-d H:i:s'));
        $this->assertSame(25_125_000, DB::table('mlm_volume_entries')->value('quantity_millionths'));
    }

    public function test_an_effective_moment_given_in_another_timezone_is_stored_as_the_same_instant(): void
    {
        $entry = $this->record(Member::factory()->create(), '1', 'k', at: CarbonImmutable::parse('2026-06-01 10:00:00', 'Asia/Jakarta'));

        $this->assertSame('2026-06-01 03:00:00', $entry->effective_at->format('Y-m-d H:i:s'));
    }

    public function test_the_program_is_read_from_the_database_not_the_instance(): void
    {
        $member = Member::factory()->create();
        $realProgram = $member->program_id;

        // An unsaved change claiming the member is in another program.
        $member->program_id = Program::factory()->create()->id;

        $entry = $this->record($member, '10', 'k');

        $this->assertSame($realProgram, $entry->program_id);
    }

    public function test_one_source_may_produce_several_entries(): void
    {
        $alice = Member::factory()->create();
        $bob = Member::factory()->for($alice->program)->create();

        $this->record($alice, '10', 'order:ORD-9:alice:sales', sourceId: 'ORD-9');
        $this->record($alice, '4', 'order:ORD-9:alice:qualification', type: 'qualification', sourceId: 'ORD-9');
        $this->record($bob, '2', 'order:ORD-9:bob:sales', sourceId: 'ORD-9');

        $this->assertSame(3, DB::table('mlm_volume_entries')->where('source_type', 'order')->where('source_id', 'ORD-9')->count());
    }

    public function test_an_integer_source_id_is_stored_as_a_string(): void
    {
        $entry = $this->record(Member::factory()->create(), '1', 'k', sourceId: 1002);

        $this->assertSame('1002', $entry->source_id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedQuantities(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-5'],
        ];
    }

    public function test_the_largest_single_entry_is_recorded_exactly(): void
    {
        $entry = $this->record(Member::factory()->create(), '999999999999.999999', 'k');

        $this->assertSame('999999999999.999999', $entry->quantity->value());
        $this->assertSame(999_999_999_999_999_999, DB::table('mlm_volume_entries')->value('quantity_millionths'));
    }

    public function test_the_exact_millionths_string_is_stored_as_an_integer(): void
    {
        $this->record(Member::factory()->create(), '25.5', 'k');

        // Inserted as the string "25500000" — never through a PHP int or float.
        $this->assertSame('integer', DB::selectOne('select typeof(quantity_millionths) as type from mlm_volume_entries')->type);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function oversizedQuantities(): array
    {
        return [
            'thirteen integer digits' => ['1000000000000'],
            'a total beyond a 64-bit integer' => ['123456789012345678.901234'],
        ];
    }

    #[DataProvider('oversizedQuantities')]
    public function test_a_quantity_too_large_for_one_entry_is_refused(string $quantity): void
    {
        $this->expectException(InvalidVolumeEntry::class);
        $this->expectExceptionMessage('at most 12 integer digits');

        new RecordVolume(Member::factory()->make(), 'sales', Quantity::of($quantity), 'order', 'ORD-1', 'k', now());
    }

    #[DataProvider('refusedQuantities')]
    public function test_a_recorded_quantity_must_be_positive(string $quantity): void
    {
        $this->expectException(InvalidVolumeEntry::class);
        $this->expectExceptionMessage('must be positive');

        new RecordVolume(Member::factory()->make(), 'sales', Quantity::of($quantity), 'order', 'ORD-1', 'k', now());
    }

    public function test_a_float_quantity_cannot_reach_the_recorder(): void
    {
        // The command takes a Quantity, and a float is not one — in strict
        // and coercive callers alike, since objects are never coerced.
        $this->expectException(TypeError::class);

        new RecordVolume(Member::factory()->make(), 'sales', 1.1, 'order', 'ORD-1', 'k', now()); // @phpstan-ignore argument.type
    }

    /**
     * @return array<string, array{string, string, string|int, string, string}>
     */
    public static function refusedIdentifiers(): array
    {
        return [
            'an uppercase type' => ['Sales', 'order', 'ORD-1', 'k', 'volume type'],
            'a type with a space' => ['team sales', 'order', 'ORD-1', 'k', 'volume type'],
            'a class name as a type' => ['App\\Models\\Order', 'order', 'ORD-1', 'k', 'volume type'],
            'an empty type' => ['', 'order', 'ORD-1', 'k', 'volume type'],
            'a type over 64 characters' => [str_repeat('a', 65), 'order', 'ORD-1', 'k', 'volume type'],
            'a type starting with a dot' => ['.sales', 'order', 'ORD-1', 'k', 'volume type'],
            'an uppercase source type' => ['sales', 'Order', 'ORD-1', 'k', 'volume source type'],
            'an empty source id' => ['sales', 'order', '', 'k', 'volume source id'],
            'a blank source id' => ['sales', 'order', '   ', 'k', 'volume source id'],
            'a padded source id' => ['sales', 'order', ' ORD-1', 'k', 'volume source id'],
            'a source id over 128 characters' => ['sales', 'order', str_repeat('x', 129), 'k', 'volume source id'],
            'an empty idempotency key' => ['sales', 'order', 'ORD-1', '', 'volume idempotency key'],
            'a key with a newline' => ['sales', 'order', 'ORD-1', "k\n", 'volume idempotency key'],
        ];
    }

    #[DataProvider('refusedIdentifiers')]
    public function test_identifiers_are_refused_rather_than_rewritten(string $type, string $sourceType, string|int $sourceId, string $key, string $field): void
    {
        $this->expectException(InvalidVolumeEntry::class);
        $this->expectExceptionMessage("The {$field} must be");

        new RecordVolume(Member::factory()->make(), $type, Quantity::of('1'), $sourceType, $sourceId, $key, now());
    }

    public function test_the_package_prescribes_no_volume_types(): void
    {
        $member = Member::factory()->create();

        foreach (['sales', 'retail', 'wholesale', 'team-sales', 'custom.program_value', '2026-q1'] as $index => $type) {
            $this->record($member, '1', "k{$index}", type: $type);
        }

        $this->assertSame(6, DB::table('mlm_volume_entries')->distinct()->count('type'));
    }

    public function test_recording_touches_neither_genealogy_nor_plans(): void
    {
        $tables = ['mlm_sponsor_edges', 'mlm_placement_edges', 'mlm_genealogy_paths', 'mlm_plans', 'mlm_plan_versions'];

        $this->reverse($this->record(Member::factory()->create(), '10', 'k'), 'r');

        foreach ($tables as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} was written.");
        }
    }
}
