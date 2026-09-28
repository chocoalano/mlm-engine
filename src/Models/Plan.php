<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PandaBear\Mlm\Database\Factories\PlanFactory;
use PandaBear\Mlm\Planning\PlanVersionStatus;

/**
 * The stable identity of a business plan within a program. Everything that
 * can change between revisions belongs to a PlanVersion, not here.
 *
 * @property string $id
 * @property string $program_id
 * @property string $code
 * @property string $name
 * @property-read Program $program
 * @property-read Collection<int, PlanVersion> $versions
 */
final class Plan extends MlmModel
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $table = 'mlm_plans';

    /**
     * No `program_id`: a plan is created through its program,
     * `$program->plans()->create([...])`, never by naming one.
     */
    protected $fillable = [
        'code',
        'name',
    ];

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return HasMany<PlanVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class);
    }

    /**
     * The active version, if there is one.
     *
     * A query rather than a relation: more than one active version means the
     * lifecycle was bypassed, and that is reported — `sole()` throws
     * `MultipleRecordsFoundException` — rather than one of them being picked.
     */
    public function currentActiveVersion(): ?PlanVersion
    {
        try {
            return $this->versions()->where('status', PlanVersionStatus::Active)->sole();
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }
}
