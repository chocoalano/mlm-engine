<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;

final class PlanTest extends DatabaseTestCase
{
    public function test_it_can_be_persisted_through_its_program(): void
    {
        $program = Program::factory()->create();

        $plan = $program->plans()->create(['code' => 'STANDARD', 'name' => 'Standard Plan']);

        $this->assertDatabaseHas('mlm_plans', [
            'id' => $plan->id,
            'program_id' => $program->id,
            'code' => 'STANDARD',
            'name' => 'Standard Plan',
        ]);
    }

    public function test_it_gets_a_ulid(): void
    {
        $plan = Plan::factory()->create();

        $this->assertTrue(Str::isUlid($plan->id));
    }

    public function test_it_belongs_to_its_program_which_has_many_plans(): void
    {
        $program = Program::factory()->create();
        $plans = Plan::factory()->count(2)->for($program)->create();

        $this->assertTrue($plans[0]->program->is($program));
        $this->assertEqualsCanonicalizing($plans->modelKeys(), $program->plans->modelKeys());
    }

    public function test_its_program_is_not_mass_assignable(): void
    {
        $program = Program::factory()->create();

        $plan = new Plan(['program_id' => $program->id, 'code' => 'STANDARD']);

        $this->assertNull($plan->program_id);
    }

    public function test_its_code_is_unique_within_its_program(): void
    {
        $program = Program::factory()->create();
        $program->plans()->create(['code' => 'STANDARD', 'name' => 'Standard Plan']);

        $this->expectException(UniqueConstraintViolationException::class);

        $program->plans()->create(['code' => 'STANDARD', 'name' => 'Another Plan']);
    }

    public function test_the_same_code_is_allowed_in_another_program(): void
    {
        Program::factory()->create()->plans()->create(['code' => 'STANDARD', 'name' => 'Standard Plan']);
        Program::factory()->create()->plans()->create(['code' => 'STANDARD', 'name' => 'Standard Plan']);

        $this->assertSame(2, Plan::where('code', 'STANDARD')->count());
    }

    public function test_it_has_many_versions(): void
    {
        $plan = Plan::factory()->create();
        $version = PlanVersion::factory()->for($plan)->create();

        $this->assertTrue($plan->versions()->sole()->is($version));
    }

    public function test_it_cannot_be_deleted_while_it_has_versions(): void
    {
        $plan = Plan::factory()->create();
        PlanVersion::factory()->for($plan)->create();

        $this->expectException(QueryException::class);

        $plan->delete();
    }

    public function test_a_program_cannot_be_deleted_while_it_has_plans(): void
    {
        $plan = Plan::factory()->create();

        $this->expectException(QueryException::class);

        $plan->program->delete();
    }
}
