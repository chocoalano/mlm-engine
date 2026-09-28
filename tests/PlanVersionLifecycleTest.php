<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\InvalidPlanVersionTransition;
use PandaBear\Mlm\Exceptions\PlanVersionNotMutable;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class PlanVersionLifecycleTest extends DatabaseTestCase
{
    private const OPERATIONS = ['markValidated', 'publish', 'activate', 'archive'];

    /**
     * The one operation that moves each status forward. Active has none of
     * its own: it is superseded when a newer version is activated.
     */
    private const VALID = [
        'draft' => ['markValidated', 'validated'],
        'validated' => ['publish', 'published'],
        'published' => ['activate', 'active'],
        'superseded' => ['archive', 'archived'],
    ];

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function validSteps(): array
    {
        $steps = [];

        foreach (self::VALID as $from => [$operation, $to]) {
            $steps["{$from} -> {$to}"] = [$from, $operation, $to];
        }

        return $steps;
    }

    #[DataProvider('validSteps')]
    public function test_each_operation_moves_one_step_forward(string $from, string $operation, string $to): void
    {
        $version = $this->versionIn(PlanVersionStatus::from($from));

        $moved = $this->lifecycle()->{$operation}($version);

        $this->assertSame(PlanVersionStatus::from($to), $moved->status);
        $this->assertSame($to, $this->storedStatus($version));
    }

    public function test_activating_a_newer_version_supersedes_the_active_one(): void
    {
        $plan = Plan::factory()->create();
        $old = $this->versionIn(PlanVersionStatus::Active, $plan);

        $new = $this->lifecycle()->activate($this->versionIn(PlanVersionStatus::Published, $plan));

        $this->assertSame('superseded', $this->storedStatus($old));
        $this->assertSame(PlanVersionStatus::Active, $new->status);
        $this->assertEquals($new->activated_at, $old->fresh()?->superseded_at);
        $this->assertTrue($plan->currentActiveVersion()?->is($new));
    }

    /**
     * Every status paired with every operation that is not its next step.
     *
     * @return array<string, array{string, string}>
     */
    public static function invalidSteps(): array
    {
        $steps = [];

        foreach (PlanVersionStatus::cases() as $status) {
            foreach (self::OPERATIONS as $operation) {
                if ((self::VALID[$status->value][0] ?? null) !== $operation) {
                    $steps["{$operation} on {$status->value}"] = [$status->value, $operation];
                }
            }
        }

        return $steps;
    }

    #[DataProvider('invalidSteps')]
    public function test_an_operation_out_of_order_is_refused(string $status, string $operation): void
    {
        $version = $this->versionIn(PlanVersionStatus::from($status));

        try {
            $this->lifecycle()->{$operation}($version);
            $this->fail("{$operation} was allowed on a {$status} version.");
        } catch (InvalidPlanVersionTransition) {
            $this->assertSame($status, $this->storedStatus($version));
        }
    }

    public function test_each_transition_stamps_its_own_moment_and_nothing_later(): void
    {
        $plan = Plan::factory()->create();
        $stamps = static fn (PlanVersion $version): array => array_map(
            static fn (?CarbonImmutable $at): ?string => $at?->format('H:i'),
            $version->fresh()?->only(['validated_at', 'published_at', 'activated_at', 'superseded_at', 'archived_at']) ?? [],
        );

        $this->travelTo('2026-01-01 09:00:00');
        $v1 = $this->lifecycle()->draft($plan);
        $this->assertSame(['validated_at' => null, 'published_at' => null, 'activated_at' => null, 'superseded_at' => null, 'archived_at' => null], $stamps($v1));

        $this->travelTo('2026-01-01 10:00:00');
        $this->lifecycle()->markValidated($v1);
        $this->assertSame(['validated_at' => '10:00', 'published_at' => null, 'activated_at' => null, 'superseded_at' => null, 'archived_at' => null], $stamps($v1));

        $this->travelTo('2026-01-01 11:00:00');
        $this->lifecycle()->publish($v1);
        $this->assertSame(['validated_at' => '10:00', 'published_at' => '11:00', 'activated_at' => null, 'superseded_at' => null, 'archived_at' => null], $stamps($v1));

        $this->travelTo('2026-01-01 12:00:00');
        $this->lifecycle()->activate($v1);
        $this->assertSame(['validated_at' => '10:00', 'published_at' => '11:00', 'activated_at' => '12:00', 'superseded_at' => null, 'archived_at' => null], $stamps($v1));

        $v2 = $this->lifecycle()->publish($this->lifecycle()->markValidated($this->lifecycle()->draft($plan)));
        $this->travelTo('2026-01-01 13:00:00');
        $this->lifecycle()->activate($v2);
        $this->assertSame(['validated_at' => '10:00', 'published_at' => '11:00', 'activated_at' => '12:00', 'superseded_at' => '13:00', 'archived_at' => null], $stamps($v1));

        $this->travelTo('2026-01-01 14:00:00');
        $this->lifecycle()->archive($v1);
        $this->assertSame(['validated_at' => '10:00', 'published_at' => '11:00', 'activated_at' => '12:00', 'superseded_at' => '13:00', 'archived_at' => '14:00'], $stamps($v1));

        $this->assertContainsOnlyInstancesOf(CarbonImmutable::class, $v1->fresh()?->only(['validated_at', 'published_at', 'activated_at', 'superseded_at', 'archived_at']) ?? []);
    }

    public function test_the_first_published_version_becomes_active(): void
    {
        $plan = Plan::factory()->create();

        $this->assertNull($plan->currentActiveVersion());

        $version = $this->lifecycle()->activate($this->versionIn(PlanVersionStatus::Published, $plan));

        $this->assertTrue($plan->currentActiveVersion()?->is($version));
    }

    public function test_activation_touches_only_its_own_plan(): void
    {
        $other = $this->versionIn(PlanVersionStatus::Active);
        $plan = Plan::factory()->create();
        $this->versionIn(PlanVersionStatus::Active, $plan);

        $this->lifecycle()->activate($this->versionIn(PlanVersionStatus::Published, $plan));

        $this->assertSame('active', $this->storedStatus($other));
        $this->assertNull($other->fresh()?->superseded_at);
    }

    public function test_an_older_version_cannot_replace_a_newer_active_one(): void
    {
        $plan = Plan::factory()->create();
        $v1 = $this->lifecycle()->draft($plan);
        $v2 = $this->lifecycle()->draft($plan);
        $this->lifecycle()->publish($this->lifecycle()->markValidated($v1));
        $this->lifecycle()->activate($this->lifecycle()->publish($this->lifecycle()->markValidated($v2)));

        try {
            $this->lifecycle()->activate($v1);
            $this->fail('Version 1 replaced the active version 2.');
        } catch (InvalidPlanVersionTransition $exception) {
            $this->assertStringContainsString('only moves forward', $exception->getMessage());
            $this->assertSame('published', $this->storedStatus($v1));
            $this->assertSame('active', $this->storedStatus($v2));
        }
    }

    public function test_a_failed_activation_changes_nothing(): void
    {
        $plan = Plan::factory()->create();
        $old = $this->versionIn(PlanVersionStatus::Active, $plan);
        $new = $this->versionIn(PlanVersionStatus::Published, $plan);

        // Fail after both writes have run — the old version superseded, the
        // new one activated — so only the transaction can undo them. The
        // activation is the one update whose SET status binding is 'active'.
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update') && ($query->bindings[0] ?? null) === 'active') {
                throw new RuntimeException('Injected failure after activation.');
            }
        });

        try {
            $this->lifecycle()->activate($new);
            $this->fail('The injected failure did not surface.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure after activation.', $exception->getMessage());
        }

        $this->assertSame('active', $this->storedStatus($old));
        $this->assertSame('published', $this->storedStatus($new));
        $this->assertNull($old->fresh()?->superseded_at);
        $this->assertNull($new->fresh()?->activated_at);
    }

    public function test_a_stale_instance_cannot_move_a_version_twice(): void
    {
        $version = $this->versionIn(PlanVersionStatus::Published);
        $stale = PlanVersion::findOrFail($version->id);

        $this->lifecycle()->activate($version);

        $this->expectException(InvalidPlanVersionTransition::class);

        $this->lifecycle()->activate($stale);
    }

    public function test_more_than_one_active_version_is_reported_not_hidden(): void
    {
        $plan = Plan::factory()->create();
        $this->versionIn(PlanVersionStatus::Active, $plan);
        $published = $this->versionIn(PlanVersionStatus::Published, $plan);

        // Only a write that bypasses the lifecycle can do this.
        DB::table('mlm_plan_versions')->where('id', $published->id)->update(['status' => 'active']);

        $this->expectException(MultipleRecordsFoundException::class);

        $plan->currentActiveVersion();
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function mutability(): array
    {
        return [
            'draft' => ['draft', true],
            'validated' => ['validated', false],
            'published' => ['published', false],
            'active' => ['active', false],
            'superseded' => ['superseded', false],
            'archived' => ['archived', false],
        ];
    }

    #[DataProvider('mutability')]
    public function test_only_a_draft_is_mutable(string $status, bool $mutable): void
    {
        $version = $this->versionIn(PlanVersionStatus::from($status));

        $this->assertSame($mutable, $version->isMutable());

        if (! $mutable) {
            $this->expectException(PlanVersionNotMutable::class);
        }

        $version->assertMutable();
    }

    public function test_a_locked_version_cannot_be_deleted(): void
    {
        $version = $this->versionIn(PlanVersionStatus::Published);

        $this->expectException(PlanVersionNotMutable::class);

        $version->delete();
    }

    public function test_a_draft_can_be_deleted(): void
    {
        $version = $this->versionIn(PlanVersionStatus::Draft);

        $version->delete();

        $this->assertModelMissing($version);
    }

    /**
     * A version brought to `$status` through the lifecycle, never by writing
     * the status: a superseded version is one a newer version replaced.
     */
    private function versionIn(PlanVersionStatus $status, ?Plan $plan = null): PlanVersion
    {
        $plan ??= Plan::factory()->create();
        $lifecycle = $this->lifecycle();

        return match ($status) {
            PlanVersionStatus::Draft => $lifecycle->draft($plan),
            PlanVersionStatus::Validated => $lifecycle->markValidated($this->versionIn(PlanVersionStatus::Draft, $plan)),
            PlanVersionStatus::Published => $lifecycle->publish($this->versionIn(PlanVersionStatus::Validated, $plan)),
            PlanVersionStatus::Active => $lifecycle->activate($this->versionIn(PlanVersionStatus::Published, $plan)),
            PlanVersionStatus::Superseded => $this->superseded($plan),
            PlanVersionStatus::Archived => $lifecycle->archive($this->superseded($plan)),
        };
    }

    private function superseded(Plan $plan): PlanVersion
    {
        $version = $this->versionIn(PlanVersionStatus::Active, $plan);
        $this->versionIn(PlanVersionStatus::Active, $plan);

        return $version->refresh();
    }

    private function storedStatus(PlanVersion $version): string
    {
        return (string) DB::table('mlm_plan_versions')->where('id', $version->id)->value('status');
    }

    private function lifecycle(): PlanVersionLifecycle
    {
        return $this->app->make(PlanVersionLifecycle::class);
    }
}
