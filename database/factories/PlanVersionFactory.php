<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PandaBear\Mlm\Models\PlanVersion;

/**
 * Version 1 of a new plan, as a draft — the only state a version can be
 * created in. Later versions, and every status after draft, come from
 * `PlanVersionLifecycle`, so a factory can never produce an impossible
 * status and timestamp combination.
 *
 * @extends Factory<PlanVersion>
 */
final class PlanVersionFactory extends Factory
{
    protected $model = PlanVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => PlanFactory::new(),
            'version' => 1,
        ];
    }
}
