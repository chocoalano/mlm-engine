<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use PandaBear\Mlm\Exceptions\InvalidExternalIdentity;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PHPUnit\Framework\Attributes\DataProvider;

final class MemberTest extends DatabaseTestCase
{
    public function test_it_can_be_persisted(): void
    {
        $program = Program::factory()->create();

        $member = $program->members()->create([
            'member_code' => 'MBR-000001',
            'joined_at' => '2026-01-15 08:30:00',
        ]);

        $this->assertDatabaseHas('mlm_members', [
            'id' => $member->id,
            'program_id' => $program->id,
            'member_code' => 'MBR-000001',
        ]);
    }

    public function test_it_gets_a_ulid(): void
    {
        $member = Member::factory()->create();

        $this->assertTrue(Str::isUlid($member->id));
        $this->assertSame(26, strlen($member->id));
    }

    public function test_it_belongs_to_its_program(): void
    {
        $program = Program::factory()->create();
        $member = Member::factory()->for($program)->create();

        $this->assertTrue($member->program->is($program));
    }

    public function test_it_cannot_exist_without_a_program(): void
    {
        $this->expectException(QueryException::class);

        (new Member(['member_code' => 'ORPHAN', 'joined_at' => now()]))->save();
    }

    public function test_its_program_is_not_mass_assignable(): void
    {
        $program = Program::factory()->create();

        $member = new Member(['program_id' => $program->id, 'member_code' => 'MBR-1']);

        $this->assertNull($member->program_id);
    }

    public function test_its_code_is_unique_within_its_program(): void
    {
        $program = Program::factory()->create();
        $program->members()->create(['member_code' => 'MEMBER001', 'joined_at' => now()]);

        $this->expectException(UniqueConstraintViolationException::class);

        $program->members()->create(['member_code' => 'MEMBER001', 'joined_at' => now()]);
    }

    public function test_the_same_code_is_allowed_in_another_program(): void
    {
        Program::factory()->create()->members()->create(['member_code' => 'MEMBER001', 'joined_at' => now()]);
        Program::factory()->create()->members()->create(['member_code' => 'MEMBER001', 'joined_at' => now()]);

        $this->assertSame(2, Member::where('member_code', 'MEMBER001')->count());
    }

    public function test_it_stores_an_external_identity(): void
    {
        $member = Member::factory()->create(['external_type' => 'customer', 'external_id' => 'CUS-00192']);

        $this->assertDatabaseHas('mlm_members', [
            'id' => $member->id,
            'external_type' => 'customer',
            'external_id' => 'CUS-00192',
        ]);
    }

    public function test_the_external_identity_is_optional(): void
    {
        $program = Program::factory()->create();

        // Two members without an identity in one program: the unique index
        // must not treat their NULLs as a collision.
        Member::factory()->count(2)->for($program)->create();

        $this->assertSame(2, $program->members()->whereNull('external_type')->whereNull('external_id')->count());
    }

    public function test_an_external_identity_is_unique_within_its_program(): void
    {
        $program = Program::factory()->create();
        Member::factory()->for($program)->create(['external_type' => 'customer', 'external_id' => 'C001']);

        $this->expectException(UniqueConstraintViolationException::class);

        Member::factory()->for($program)->create(['external_type' => 'customer', 'external_id' => 'C001']);
    }

    public function test_the_same_external_identity_is_allowed_in_another_program(): void
    {
        Member::factory()->create(['external_type' => 'customer', 'external_id' => 'C001']);
        Member::factory()->create(['external_type' => 'customer', 'external_id' => 'C001']);

        $this->assertSame(2, Member::where('external_type', 'customer')->where('external_id', 'C001')->count());
    }

    public function test_an_integer_external_id_is_the_same_identity_as_its_string_form(): void
    {
        $program = Program::factory()->create();
        $member = Member::factory()->for($program)->create(['external_type' => 'user', 'external_id' => 1002]);

        $this->assertSame('1002', $member->external_id);

        $this->expectException(UniqueConstraintViolationException::class);

        Member::factory()->for($program)->create(['external_type' => 'user', 'external_id' => '1002']);
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function incompleteIdentities(): array
    {
        return [
            'type without id' => ['customer', null],
            'id without type' => [null, 'C001'],
            'empty type' => ['', 'C001'],
            'blank id' => ['customer', '  '],
        ];
    }

    #[DataProvider('incompleteIdentities')]
    public function test_it_refuses_an_incomplete_external_identity(?string $type, ?string $id): void
    {
        $program = Program::factory()->create();

        try {
            Member::factory()->for($program)->create(['external_type' => $type, 'external_id' => $id]);
            $this->fail('An incomplete external identity was saved.');
        } catch (InvalidExternalIdentity) {
            $this->assertSame(0, $program->members()->count());
        }
    }

    public function test_joined_at_is_an_immutable_datetime(): void
    {
        $member = Member::factory()->create(['joined_at' => '2026-01-15 08:30:00']);

        $joinedAt = $member->fresh()?->joined_at;

        $this->assertInstanceOf(CarbonImmutable::class, $joinedAt);
        $this->assertSame('2026-01-15 08:30:00', $joinedAt->format('Y-m-d H:i:s'));
    }
}
