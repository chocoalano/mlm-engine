<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;

final class ProgramTest extends DatabaseTestCase
{
    public function test_it_can_be_persisted(): void
    {
        $program = Program::create(['code' => 'MAIN', 'name' => 'Main Distributor Program']);

        $this->assertDatabaseHas('mlm_programs', [
            'id' => $program->id,
            'code' => 'MAIN',
            'name' => 'Main Distributor Program',
        ]);
    }

    public function test_it_gets_a_ulid(): void
    {
        $program = Program::factory()->create();

        $this->assertTrue(Str::isUlid($program->id));
        $this->assertSame(26, strlen($program->id));
        $this->assertFalse($program->getIncrementing());
        $this->assertSame('string', $program->getKeyType());
    }

    public function test_its_code_is_unique(): void
    {
        Program::create(['code' => 'MAIN', 'name' => 'Main Distributor Program']);

        $this->expectException(UniqueConstraintViolationException::class);

        Program::create(['code' => 'MAIN', 'name' => 'Another Program']);
    }

    public function test_its_name_need_not_be_unique(): void
    {
        Program::create(['code' => 'A', 'name' => 'Partner Program']);
        Program::create(['code' => 'B', 'name' => 'Partner Program']);

        $this->assertSame(2, Program::where('name', 'Partner Program')->count());
    }

    public function test_it_has_many_members(): void
    {
        $program = Program::factory()->create();

        Member::factory()->count(2)->for($program)->create();

        $this->assertCount(2, $program->members);
        $this->assertContainsOnlyInstancesOf(Member::class, $program->members);
    }

    public function test_members_are_scoped_to_their_program(): void
    {
        $programA = Program::factory()->create();
        $programB = Program::factory()->create();

        // The same member code and the same external identity in both.
        $identity = ['member_code' => 'MEMBER001', 'external_type' => 'customer', 'external_id' => 'C001', 'joined_at' => now()];
        $memberA = $programA->members()->create($identity);
        $memberB = $programB->members()->create($identity);

        $this->assertTrue($programA->members()->sole()->is($memberA));
        $this->assertTrue($programB->members()->sole()->is($memberB));
        $this->assertTrue($memberA->program->is($programA));
        $this->assertTrue($memberB->program->is($programB));
    }

    public function test_it_cannot_be_deleted_while_it_has_members(): void
    {
        $program = Program::factory()->create();
        Member::factory()->for($program)->create();

        $this->expectException(QueryException::class);

        $program->delete();
    }

    public function test_it_can_be_deleted_without_members(): void
    {
        $program = Program::factory()->create();

        $program->delete();

        $this->assertDatabaseMissing('mlm_programs', ['id' => $program->id]);
    }

    public function test_it_uses_the_default_connection_when_none_is_configured(): void
    {
        $program = Program::factory()->create();

        $this->assertNull((new Program)->getConnectionName());
        $this->assertSame('testing', $program->getConnection()->getName());
    }
}
