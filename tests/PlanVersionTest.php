<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PandaBear\Mlm\Exceptions\InvalidPlanVersionTransition;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Planning\PlanVersionStatus;

final class PlanVersionTest extends DatabaseTestCase
{
    public function test_a_draft_is_persisted_for_its_plan(): void
    {
        $plan = Plan::factory()->create();

        $version = $this->lifecycle()->draft($plan);

        $this->assertDatabaseHas('mlm_plan_versions', [
            'id' => $version->id,
            'plan_id' => $plan->id,
            'version' => 1,
            'status' => 'draft',
        ]);
    }

    public function test_it_gets_a_ulid(): void
    {
        $version = PlanVersion::factory()->create();

        $this->assertTrue(Str::isUlid($version->id));
    }

    public function test_it_belongs_to_its_plan(): void
    {
        $plan = Plan::factory()->create();

        $this->assertTrue($this->lifecycle()->draft($plan)->plan->is($plan));
    }

    public function test_drafts_are_numbered_one_after_another_within_their_plan(): void
    {
        $planA = Plan::factory()->create();
        $planB = Plan::factory()->create();

        $numbers = [
            $this->lifecycle()->draft($planA)->version,
            $this->lifecycle()->draft($planA)->version,
            $this->lifecycle()->draft($planB)->version,
            $this->lifecycle()->draft($planA)->version,
        ];

        $this->assertSame([1, 2, 1, 3], $numbers);
    }

    public function test_its_version_number_is_unique_within_its_plan(): void
    {
        $plan = Plan::factory()->create();
        PlanVersion::factory()->for($plan)->create(['version' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);

        PlanVersion::factory()->for($plan)->create(['version' => 1]);
    }

    public function test_the_same_version_number_is_allowed_under_another_plan(): void
    {
        PlanVersion::factory()->create(['version' => 1]);
        PlanVersion::factory()->create(['version' => 1]);

        $this->assertSame(2, PlanVersion::where('version', 1)->count());
    }

    public function test_it_starts_as_a_draft_cast_to_the_status_enum(): void
    {
        $version = PlanVersion::factory()->create()->fresh();

        $this->assertSame(PlanVersionStatus::Draft, $version?->status);
        $this->assertSame('draft', DB::table('mlm_plan_versions')->value('status'));
    }

    public function test_its_status_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new PlanVersion(['status' => 'active']);
    }

    public function test_update_cannot_change_its_status(): void
    {
        $version = PlanVersion::factory()->create();

        try {
            $version->update(['status' => 'active']);
            $this->fail('update() changed a plan version status.');
        } catch (MassAssignmentException) {
            $this->assertSame('draft', DB::table('mlm_plan_versions')->value('status'));
        }
    }

    public function test_a_status_set_directly_is_refused_on_save(): void
    {
        $version = PlanVersion::factory()->create();
        $version->status = PlanVersionStatus::Active;

        try {
            $version->save();
            $this->fail('A plan version status was saved outside the lifecycle.');
        } catch (InvalidPlanVersionTransition $exception) {
            $this->assertStringContainsString('[status]', $exception->getMessage());
            $this->assertSame('draft', DB::table('mlm_plan_versions')->value('status'));
        }
    }

    public function test_a_version_cannot_be_created_past_draft(): void
    {
        $version = (new PlanVersion)->forceFill([
            'plan_id' => Plan::factory()->create()->id,
            'version' => 1,
            'status' => PlanVersionStatus::Active,
            'activated_at' => now(),
        ]);

        try {
            $version->save();
            $this->fail('A plan version was created past draft.');
        } catch (InvalidPlanVersionTransition $exception) {
            $this->assertStringContainsString('[status, activated_at]', $exception->getMessage());
            $this->assertSame(0, PlanVersion::count());
        }
    }

    public function test_a_stamp_set_directly_is_refused_on_save(): void
    {
        $version = $this->lifecycle()->markValidated(PlanVersion::factory()->create());
        $validatedAt = $version->validated_at;

        $version->validated_at = now()->addDay();

        $this->expectException(InvalidPlanVersionTransition::class);

        try {
            $version->save();
        } finally {
            $this->assertEquals($validatedAt, $version->fresh()?->validated_at);
        }
    }

    public function test_touching_it_leaves_the_lifecycle_alone(): void
    {
        $this->travelTo('2026-01-01 09:00:00');
        $version = $this->lifecycle()->markValidated(PlanVersion::factory()->create());

        $this->travelTo('2026-01-02 09:00:00');
        $version->touch();

        $fresh = $version->fresh();
        $this->assertSame('2026-01-02 09:00:00', $fresh?->updated_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-01 09:00:00', $fresh->validated_at?->format('Y-m-d H:i:s'));
        $this->assertSame(PlanVersionStatus::Validated, $fresh->status);
    }

    private function lifecycle(): PlanVersionLifecycle
    {
        return $this->app->make(PlanVersionLifecycle::class);
    }
}
