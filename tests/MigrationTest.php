<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

final class MigrationTest extends TestCase
{
    private const TABLES = ['mlm_programs', 'mlm_members', 'mlm_plans', 'mlm_plan_versions'];

    public function test_migrate_creates_the_package_tables(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} is missing.");
        }
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

    public function test_the_plans_table_has_exactly_the_minimum_columns(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'program_id', 'code', 'name', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_plans'),
        );
    }

    public function test_the_plan_versions_table_has_exactly_the_minimum_columns(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [
                'id', 'plan_id', 'version', 'status',
                'validated_at', 'published_at', 'activated_at', 'superseded_at', 'archived_at',
                'created_at', 'updated_at',
            ],
            Schema::getColumnListing('mlm_plan_versions'),
        );
    }

    public function test_the_unique_indexes_scope_identities_to_their_owner(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame([['code']], $this->uniqueIndexColumns('mlm_programs'));
        $this->assertEqualsCanonicalizing(
            [['program_id', 'member_code'], ['program_id', 'external_type', 'external_id']],
            $this->uniqueIndexColumns('mlm_members'),
        );
        $this->assertSame([['program_id', 'code']], $this->uniqueIndexColumns('mlm_plans'));
        $this->assertSame([['plan_id', 'version']], $this->uniqueIndexColumns('mlm_plan_versions'));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function ownedTables(): array
    {
        return [
            'members belong to programs' => ['mlm_members', 'program_id', 'mlm_programs'],
            'plans belong to programs' => ['mlm_plans', 'program_id', 'mlm_programs'],
            'plan versions belong to plans' => ['mlm_plan_versions', 'plan_id', 'mlm_plans'],
        ];
    }

    #[DataProvider('ownedTables')]
    public function test_a_foreign_key_references_the_owner_and_restricts_its_deletion(string $table, string $column, string $owner): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $foreignKeys = Schema::getForeignKeys($table);

        $this->assertCount(1, $foreignKeys);
        $this->assertSame([$column], $foreignKeys[0]['columns']);
        $this->assertSame($owner, $foreignKeys[0]['foreign_table']);
        $this->assertSame(['id'], $foreignKeys[0]['foreign_columns']);
        $this->assertSame('restrict', $foreignKeys[0]['on_delete']);
    }

    public function test_the_plan_version_status_defaults_to_draft(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $status = collect(Schema::getColumns('mlm_plan_versions'))->firstWhere('name', 'status');

        $this->assertIsArray($status);
        $this->assertStringContainsString('draft', (string) $status['default']);
    }

    public function test_rollback_drops_the_package_tables(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->artisan('migrate:rollback', [
            '--path' => dirname(__DIR__).'/database/migrations',
            '--realpath' => true,
        ])->assertSuccessful();

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} survived the rollback.");
        }
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
