<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;

/**
 * One named condition tree of a plan component, in the safe rule language.
 * `$rule->definition` reads it back as a `RuleDefinition`; stored JSON outside
 * the language is refused, never read as an empty or partial rule.
 *
 * Read-only through Eloquent, as components are: `PlanDefinitionEditor`
 * writes rules.
 *
 * @property string $id
 * @property string $plan_component_id
 * @property string $key
 * @property string $name
 * @property-read RuleDefinition $definition
 * @property int $position
 * @property-read PlanComponent $component
 */
final class PlanRule extends MlmModel
{
    protected $table = 'mlm_plan_rules';

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
     * @return BelongsTo<PlanComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(PlanComponent::class, 'plan_component_id');
    }

    /**
     * @return Attribute<RuleDefinition, never>
     *
     * @throws InvalidRuleDefinition
     */
    protected function definition(): Attribute
    {
        return Attribute::get(static fn (mixed $value): RuleDefinition => RuleDefinition::fromJson($value));
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
