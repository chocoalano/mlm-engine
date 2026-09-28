<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use PandaBear\Mlm\Models\Plan;

/**
 * @extends Factory<Plan>
 */
final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'PLN-'.Str::upper(Str::random(10));

        return [
            'program_id' => ProgramFactory::new(),
            'code' => $code,
            'name' => "Plan {$code}",
        ];
    }
}
