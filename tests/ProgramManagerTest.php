<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Exceptions\ConflictingProgramRecord;
use PandaBear\Mlm\Exceptions\InvalidExternalIdentity;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Program\ProgramManager;

/**
 * Programs, members and plans created through one service: the rules the
 * models and the schema already hold — codes unique, a member's identity
 * unique within its program, a member joining through its program — with a
 * taken code refused as a domain answer and nothing written.
 */
final class ProgramManagerTest extends DatabaseTestCase
{
    public function test_a_program_is_created_once_per_code(): void
    {
        $program = $this->manager()->create('MAIN', 'Main Program');

        $this->assertSame(['MAIN', 'Main Program'], [$program->code, $program->name]);
        $this->assertTrue($program->exists);

        $this->expectException(ConflictingProgramRecord::class);
        $this->expectExceptionMessage('A program with code "MAIN" already exists.');

        try {
            $this->manager()->create('MAIN', 'Another');
        } finally {
            $this->assertSame(1, Program::query()->count());
        }
    }

    public function test_blank_or_oversized_facts_are_refused(): void
    {
        foreach ([['', 'Name'], ['CODE', '   '], [str_repeat('C', 65), 'Name']] as [$code, $name]) {
            try {
                $this->manager()->create($code, $name);
                $this->fail('A blank or oversized program was created.');
            } catch (ConflictingProgramRecord $exception) {
                $this->assertStringContainsString('is required', $exception->getMessage());
            }
        }

        $this->assertSame(0, Program::query()->count());
    }

    public function test_a_member_joins_through_its_program_with_a_unique_code_and_identity(): void
    {
        $program = $this->manager()->create('MAIN', 'Main');
        $other = $this->manager()->create('OTHER', 'Other');
        $joined = CarbonImmutable::parse('2026-01-15 08:30:00');

        $member = $this->manager()->join($program, 'M-1', $joined, 'user', '42');

        $this->assertSame([$program->id, 'M-1', 'user', '42', '2026-01-15 08:30:00'], [$member->program_id, $member->member_code, $member->external_type, $member->external_id, $member->joined_at->format('Y-m-d H:i:s')]);

        // The same code and identity in another program is another member.
        $this->assertSame($other->id, $this->manager()->join($other, 'M-1', $joined, 'user', '42')->program_id);

        foreach ([
            'code "M-1"' => fn () => $this->manager()->join($program, 'M-1', $joined),
            'external identity user:42' => fn () => $this->manager()->join($program, 'M-2', $joined, 'user', '42'),
        ] as $what => $join) {
            try {
                $join();
                $this->fail("A member's {$what} was taken twice.");
            } catch (ConflictingProgramRecord $exception) {
                $this->assertStringContainsString("already has a member with {$what}", $exception->getMessage());
            }
        }

        $this->assertSame(1, $program->members()->count());
    }

    public function test_half_an_external_identity_is_refused(): void
    {
        $program = $this->manager()->create('MAIN', 'Main');

        $this->expectException(InvalidExternalIdentity::class);

        try {
            $this->manager()->join($program, 'M-1', CarbonImmutable::now(), 'user', null);
        } finally {
            $this->assertSame(0, Member::query()->count());
        }
    }

    public function test_a_plan_is_created_once_per_code_within_its_program_and_without_a_version(): void
    {
        $program = $this->manager()->create('MAIN', 'Main');
        $other = $this->manager()->create('OTHER', 'Other');

        $plan = $this->manager()->addPlan($program, 'COMP', 'Compensation');

        $this->assertSame([$program->id, 'COMP', 'Compensation', 0], [$plan->program_id, $plan->code, $plan->name, $plan->versions()->count()]);
        $this->assertSame($other->id, $this->manager()->addPlan($other, 'COMP', 'Compensation')->program_id);

        $this->expectException(ConflictingProgramRecord::class);
        $this->expectExceptionMessage('already has a plan with code "COMP"');

        try {
            $this->manager()->addPlan($program, 'COMP', 'Again');
        } finally {
            $this->assertSame(2, Plan::query()->count());
        }
    }

    private function manager(): ProgramManager
    {
        return $this->app->make(ProgramManager::class);
    }
}
