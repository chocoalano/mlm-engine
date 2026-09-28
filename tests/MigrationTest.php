<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Support\Facades\Schema;

final class MigrationTest extends TestCase
{
    public function test_migrate_creates_the_package_tables(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('mlm_programs'));
        $this->assertTrue(Schema::hasTable('mlm_members'));
    }

    public function test_the_programs_table_has_exactly_the_minimum_columns(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'code', 'name', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_programs'),
        );
    }

    public function test_the_members_table_has_exactly_the_minimum_columns(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'program_id', 'member_code', 'external_type', 'external_id', 'joined_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_members'),
        );
    }

    public function test_the_unique_indexes_scope_identities_to_their_program(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame([['code']], $this->uniqueIndexColumns('mlm_programs'));
        $this->assertEqualsCanonicalizing(
            [['program_id', 'member_code'], ['program_id', 'external_type', 'external_id']],
            $this->uniqueIndexColumns('mlm_members'),
        );
    }

    public function test_members_reference_programs_and_restrict_their_deletion(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $foreignKeys = Schema::getForeignKeys('mlm_members');

        $this->assertCount(1, $foreignKeys);
        $this->assertSame(['program_id'], $foreignKeys[0]['columns']);
        $this->assertSame('mlm_programs', $foreignKeys[0]['foreign_table']);
        $this->assertSame(['id'], $foreignKeys[0]['foreign_columns']);
        $this->assertSame('restrict', $foreignKeys[0]['on_delete']);
    }

    public function test_rollback_drops_the_package_tables(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->artisan('migrate:rollback', [
            '--path' => dirname(__DIR__).'/database/migrations',
            '--realpath' => true,
        ])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('mlm_members'));
        $this->assertFalse(Schema::hasTable('mlm_programs'));
    }

    /**
     * Unique, non-primary indexes, as column lists.
     *
     * @return list<list<string>>
     */
    private function uniqueIndexColumns(string $table): array
    {
        $unique = array_filter(
            Schema::getIndexes($table),
            static fn (array $index): bool => $index['unique'] && ! $index['primary'],
        );

        return array_values(array_map(static fn (array $index): array => $index['columns'], $unique));
    }
}
