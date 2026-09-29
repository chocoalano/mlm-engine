<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Planning\DefinitionInput;

/**
 * One named configuration unit of a plan version: the key of the trusted
 * driver it selects, and that driver's parameters — inert data, a JSON
 * object. Its rules belong to it.
 *
 * Read-only through Eloquent: `PlanDefinitionEditor` writes components, so
 * none can change once its version is validated. Creating, updating or
 * deleting one through the model is refused. Query-builder writes bypass
 * this, as they bypass every Eloquent rule.
 *
 * @property string $id
 * @property string $plan_version_id
 * @property string $key
 * @property string $driver
 * @property string $name
 * @property-read array<string, mixed> $parameters
 * @property int $position
 * @property-read PlanVersion $planVersion
 * @property-read Collection<int, PlanRule> $rules
 * @property-read Collection<int, CalculationRun> $calculationRuns
 */
final class PlanComponent extends MlmModel
{
    protected $table = 'mlm_plan_components';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PlanVersion, $this>
     */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    /**
     * In order: by position, then id.
     *
     * @return HasMany<PlanRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(PlanRule::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<CalculationRun, $this>
     */
    public function calculationRuns(): HasMany
    {
        return $this->hasMany(CalculationRun::class, 'plan_component_id');
    }

    /**
     * The stored parameters, with their keys in canonical order. Stored text
     * that is not a parameters object is refused, not read as something else.
     *
     * @return Attribute<array<string, mixed>, never>
     */
    protected function parameters(): Attribute
    {
        return Attribute::get(fn (mixed $value): array => DefinitionInput::stored($value, "parameters of component \"{$this->getAttribute('key')}\""));
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw InvalidPlanDefinition::outsideEditor(self::class);
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
