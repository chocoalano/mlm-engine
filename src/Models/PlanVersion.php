<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PandaBear\Mlm\Database\Factories\PlanVersionFactory;
use PandaBear\Mlm\Exceptions\InvalidPlanVersionTransition;
use PandaBear\Mlm\Exceptions\PlanVersionNotMutable;
use PandaBear\Mlm\Planning\PlanVersionStatus;

/**
 * One numbered revision of a plan, and the owner of its whole definition:
 * its components and their rules (ADR-014).
 *
 * Every column is owned by `PlanVersionLifecycle`: it creates versions and
 * moves them between statuses. The model only guards — a version is born a
 * draft, its lifecycle columns never change through a plain Eloquent save,
 * and a locked version cannot be deleted. Query-builder writes bypass these
 * guards, as they bypass every Eloquent rule.
 *
 * @property string $id
 * @property string $plan_id
 * @property int $version
 * @property PlanVersionStatus $status
 * @property CarbonImmutable|null $validated_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $superseded_at
 * @property CarbonImmutable|null $archived_at
 * @property-read Plan $plan
 * @property-read Collection<int, PlanComponent> $components
 * @property-read Collection<int, CalculationRun> $calculationRuns
 */
final class PlanVersion extends MlmModel
{
    /** @use HasFactory<PlanVersionFactory> */
    use HasFactory;

    /**
     * The column each transition stamps, keyed by the status it enters.
     */
    private const STAMPS = [
        'validated' => 'validated_at',
        'published' => 'published_at',
        'active' => 'activated_at',
        'superseded' => 'superseded_at',
        'archived' => 'archived_at',
    ];

    private const LIFECYCLE_COLUMNS = ['plan_id', 'version', 'status', ...self::STAMPS];

    protected $table = 'mlm_plan_versions';

    /**
     * Nothing is mass assignable: `new PlanVersion(['status' => 'active'])`
     * throws `MassAssignmentException` instead of quietly succeeding.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => PlanVersionStatus::class,
            ...array_fill_keys(self::STAMPS, 'immutable_datetime'),
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The version's definition, in order: by position, then id. Written by
     * `PlanDefinitionEditor` alone.
     *
     * @return HasMany<PlanComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(PlanComponent::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<CalculationRun, $this>
     */
    public function calculationRuns(): HasMany
    {
        return $this->hasMany(CalculationRun::class);
    }

    public function isMutable(): bool
    {
        return $this->status->isMutable();
    }

    /**
     * For anything that belongs to this version's definition to call before
     * it changes — on a freshly locked copy, as `PlanDefinitionEditor` does.
     *
     * @throws PlanVersionNotMutable
     */
    public function assertMutable(): void
    {
        if (! $this->isMutable()) {
            throw PlanVersionNotMutable::locked($this);
        }
    }

    /**
     * @internal the column `PlanVersionLifecycle` stamps when a version enters `$status`
     */
    public static function stampColumn(PlanVersionStatus $status): ?string
    {
        return self::STAMPS[$status->value] ?? null;
    }

    protected static function booted(): void
    {
        self::creating(static function (PlanVersion $version): void {
            $set = array_keys(array_filter(
                $version->only(['status', ...self::STAMPS]),
                static fn (mixed $value, string $column): bool => $column === 'status'
                    ? $value !== PlanVersionStatus::Draft
                    : $value !== null,
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($set !== []) {
                throw InvalidPlanVersionTransition::outsideLifecycle($version, $set);
            }
        });

        self::updating(static function (PlanVersion $version): void {
            $changed = array_values(array_intersect(self::LIFECYCLE_COLUMNS, array_keys($version->getDirty())));

            if ($changed !== []) {
                throw InvalidPlanVersionTransition::outsideLifecycle($version, $changed);
            }
        });

        self::deleting(static function (PlanVersion $version): void {
            $version->assertMutable();
        });
    }

    protected static function newFactory(): PlanVersionFactory
    {
        return PlanVersionFactory::new();
    }
}
